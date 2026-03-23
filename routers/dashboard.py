import os
from datetime import datetime, date
from fastapi import APIRouter, Depends, HTTPException, Query
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy import select, func, distinct, and_, or_, case

from db import get_db
from models import (
    Business, Category, City, Company, EmailBatch, BatchEmail, SentEmail, CreditTransaction,
    UnsubscribedEmail,
)
from schemas import (
    DashboardStats, CategoryCount, EmailPreview, PurchaseBatchRequest,
    BatchOut, BatchEmailOut, SendEmailRequest, SentEmailOut,
    SmtpSettingsIn, SmtpSettingsOut, SmtpTestRequest,
)
from dependencies import get_current_company, require_approved
from email_service import queue_emails, send_test_email

CREDIT_PRICE = float(os.getenv("CREDIT_PRICE_PER_EMAIL", "1"))

router = APIRouter(prefix="/dashboard", tags=["Dashboard"])


def _source_filter(company):
    sources = company.get_allowed_sources()
    if not sources:
        return []
    return [Business.source.in_(sources)]


def _unsub_filter():
    unsub_subq = select(UnsubscribedEmail.email).scalar_subquery()
    return Business.email.notin_(unsub_subq)


def _is_premium(company) -> bool:
    return (
        company.plan == "premium"
        and company.plan_expires_at is not None
        and company.plan_expires_at > datetime.utcnow()
    )


async def _reset_daily_sends_if_needed(db, company):
    today = date.today()
    if company.daily_sends_reset_at is None or company.daily_sends_reset_at.date() < today:
        company.daily_sends_used = 0
        company.daily_sends_reset_at = datetime.utcnow()
        await db.commit()


@router.get("/stats", response_model=DashboardStats)
async def stats(
    company: Company = Depends(get_current_company),
    db: AsyncSession = Depends(get_db),
):
    sf = _source_filter(company)

    total_emails_q = await db.execute(
        select(func.count(Business.id))
        .where(Business.email.isnot(None), Business.email.contains("@"), *sf)
    )
    total_emails = total_emails_q.scalar() or 0

    total_biz_q = await db.execute(select(func.count(Business.id)).where(*sf))
    total_businesses = total_biz_q.scalar() or 0

    with_web_q = await db.execute(
        select(func.count(Business.id))
        .where(Business.website.isnot(None), Business.website != "", *sf)
    )
    total_with_website = with_web_q.scalar() or 0
    total_without_website = total_businesses - total_with_website

    total_cats_q = await db.execute(
        select(func.count(distinct(Category.id)))
        .join(Business, Business.category_id == Category.id)
        .where(*sf)
    )
    total_categories = total_cats_q.scalar() or 0

    total_cities_q = await db.execute(
        select(func.count(distinct(City.id)))
        .join(Business, Business.city_id == City.id)
        .where(*sf)
    )
    total_cities = total_cities_q.scalar() or 0

    purchased_q = await db.execute(
        select(func.count(BatchEmail.id))
        .join(EmailBatch, BatchEmail.batch_id == EmailBatch.id)
        .where(EmailBatch.company_id == company.id)
    )
    purchased = purchased_q.scalar() or 0

    batches_q = await db.execute(
        select(func.count(EmailBatch.id)).where(EmailBatch.company_id == company.id)
    )
    batches = batches_q.scalar() or 0

    sent_q = await db.execute(
        select(func.count(SentEmail.id))
        .where(SentEmail.company_id == company.id, SentEmail.status == "sent")
    )
    emails_sent = sent_q.scalar() or 0

    failed_q = await db.execute(
        select(func.count(SentEmail.id))
        .where(SentEmail.company_id == company.id, SentEmail.status == "failed")
    )
    emails_failed = failed_q.scalar() or 0

    await _reset_daily_sends_if_needed(db, company)
    premium = _is_premium(company)
    remaining = max(0, company.daily_send_limit - company.daily_sends_used) if premium else 0

    return DashboardStats(
        total_emails_available=total_emails,
        total_businesses=total_businesses,
        total_with_website=total_with_website,
        total_without_website=total_without_website,
        total_categories=total_categories,
        total_cities=total_cities,
        emails_purchased=purchased,
        emails_sent=emails_sent,
        emails_failed=emails_failed,
        credit_balance=company.credit_balance,
        batches_count=batches,
        smtp_configured=bool(company.smtp_enabled and company.smtp_host),
        plan=company.plan if premium else "free",
        daily_send_limit=company.daily_send_limit if premium else 0,
        daily_sends_remaining=remaining,
    )


@router.get("/breakdown")
async def breakdown(
    company: Company = Depends(get_current_company),
    db: AsyncSession = Depends(get_db),
):
    sf = _source_filter(company)
    email_case = case(
        (and_(Business.email.isnot(None), Business.email.contains("@")), Business.email),
        else_=None,
    )

    cat_q = await db.execute(
        select(
            Category.name,
            func.count(Business.id).label("total"),
            func.count(distinct(email_case)).label("with_email"),
        )
        .join(Business, Business.category_id == Category.id)
        .where(*sf)
        .group_by(Category.name)
        .order_by(func.count(Business.id).desc())
    )
    categories = [
        {"name": name, "total": total, "with_email": we}
        for name, total, we in cat_q.all()
    ]

    city_q = await db.execute(
        select(
            City.name,
            func.count(Business.id).label("total"),
            func.count(distinct(email_case)).label("with_email"),
        )
        .join(Business, Business.city_id == City.id)
        .where(*sf)
        .group_by(City.name)
        .order_by(func.count(Business.id).desc())
    )
    cities = [
        {"name": name, "total": total, "with_email": we}
        for name, total, we in city_q.all()
    ]

    return {"categories": categories, "cities": cities}


@router.get("/categories", response_model=list[CategoryCount])
async def categories(
    company: Company = Depends(require_approved),
    db: AsyncSession = Depends(get_db),
):
    already_purchased = (
        select(BatchEmail.business_id)
        .join(EmailBatch, BatchEmail.batch_id == EmailBatch.id)
        .where(EmailBatch.company_id == company.id)
        .correlate(Company)
        .scalar_subquery()
    )

    sf = _source_filter(company)
    result = await db.execute(
        select(Category.id, Category.name, func.count(distinct(Business.id)))
        .join(Business, Business.category_id == Category.id)
        .where(
            Business.email.isnot(None),
            Business.email.contains("@"),
            Business.id.notin_(already_purchased),
            _unsub_filter(),
            *sf,
        )
        .group_by(Category.id, Category.name)
        .order_by(Category.name)
    )
    return [
        CategoryCount(category_id=cid, category_name=cname, available_count=cnt)
        for cid, cname, cnt in result.all()
    ]


@router.get("/browse-overview")
async def browse_overview(
    company: Company = Depends(require_approved),
    db: AsyncSession = Depends(get_db),
):
    already_purchased = (
        select(BatchEmail.business_id)
        .join(EmailBatch, BatchEmail.batch_id == EmailBatch.id)
        .where(EmailBatch.company_id == company.id)
        .scalar_subquery()
    )

    sf = _source_filter(company)
    email_filter = and_(
        Business.email.isnot(None),
        Business.email.contains("@"),
        Business.id.notin_(already_purchased),
        _unsub_filter(),
        *sf,
    )

    cat_q = await db.execute(
        select(Category.name, func.count(distinct(Business.id)))
        .join(Business, Business.category_id == Category.id)
        .where(email_filter)
        .group_by(Category.name)
        .order_by(func.count(distinct(Business.id)).desc())
    )
    by_category = [{"name": n, "count": c} for n, c in cat_q.all()]

    city_q = await db.execute(
        select(City.name, func.count(distinct(Business.id)))
        .join(Business, Business.city_id == City.id)
        .where(email_filter)
        .group_by(City.name)
        .order_by(func.count(distinct(Business.id)).desc())
    )
    by_city = [{"name": n, "count": c} for n, c in city_q.all()]

    total_q = await db.execute(
        select(func.count(distinct(Business.id)))
        .where(email_filter)
    )
    total = total_q.scalar() or 0

    return {
        "total_available": total,
        "by_category": by_category,
        "by_city": by_city,
    }


@router.get("/browse-emails", response_model=list[EmailPreview])
async def browse_emails(
    category: str = Query("all"),
    page: int = Query(1, ge=1),
    per_page: int = Query(20, ge=1, le=100),
    company: Company = Depends(require_approved),
    db: AsyncSession = Depends(get_db),
):
    from models import City

    sf = _source_filter(company)
    query = (
        select(Business.id, Business.name, Business.email,
               City.name.label("city_name"), Category.name.label("cat_name"))
        .join(City, Business.city_id == City.id)
        .join(Category, Business.category_id == Category.id)
        .where(Business.email.isnot(None), Business.email.contains("@"), _unsub_filter(), *sf)
    )
    if category != "all":
        query = query.where(Category.name == category)

    query = query.offset((page - 1) * per_page).limit(per_page)
    result = await db.execute(query)

    show_full = company.is_admin or _is_premium(company)
    previews = []
    for bid, bname, bemail, city_name, cat_name in result.all():
        display_email = bemail if show_full else _mask_email(bemail)
        previews.append(EmailPreview(
            id=bid, business_name=bname, email=display_email,
            city=city_name, category=cat_name,
        ))
    return previews


@router.post("/purchase-batch")
async def purchase_batch(
    req: PurchaseBatchRequest,
    company: Company = Depends(require_approved),
    db: AsyncSession = Depends(get_db),
):
    if not req.category and not req.city:
        raise HTTPException(status_code=400, detail="Specify at least a category or a city")

    premium = _is_premium(company)
    cost = 0.0 if premium else req.batch_size * CREDIT_PRICE
    if not premium and company.credit_balance < cost:
        raise HTTPException(status_code=400, detail=f"Insufficient credits. Need {cost}, have {company.credit_balance}")

    cat = None
    city_obj = None
    label_parts = []

    if req.category:
        cat_q = await db.execute(select(Category).where(Category.name == req.category))
        cat = cat_q.scalar_one_or_none()
        if not cat:
            raise HTTPException(status_code=404, detail="Category not found")
        label_parts.append(cat.name)

    if req.city:
        city_q = await db.execute(select(City).where(City.name == req.city))
        city_obj = city_q.scalar_one_or_none()
        if not city_obj:
            raise HTTPException(status_code=404, detail="City not found")
        label_parts.append(city_obj.name)

    already_purchased = (
        select(BatchEmail.business_id)
        .join(EmailBatch, BatchEmail.batch_id == EmailBatch.id)
        .where(EmailBatch.company_id == company.id)
        .scalar_subquery()
    )

    sf = _source_filter(company)
    filters = [
        Business.email.isnot(None),
        Business.email.contains("@"),
        Business.id.notin_(already_purchased),
        _unsub_filter(),
        *sf,
    ]
    if cat:
        filters.append(Business.category_id == cat.id)
    if city_obj:
        filters.append(Business.city_id == city_obj.id)

    available_q = await db.execute(
        select(Business).where(and_(*filters)).limit(req.batch_size)
    )
    available = available_q.scalars().all()
    if not available:
        raise HTTPException(status_code=400, detail="No available emails for this selection")

    actual_size = len(available)
    actual_cost = 0.0 if premium else actual_size * CREDIT_PRICE
    label = " — ".join(label_parts) if label_parts else "Custom batch"

    if not premium:
        company.credit_balance -= actual_cost
    batch = EmailBatch(
        company_id=company.id,
        category_id=cat.id if cat else None,
        city_id=city_obj.id if city_obj else None,
        label=label,
        batch_size=actual_size,
        price_paid=actual_cost,
    )
    db.add(batch)
    await db.flush()

    batch_emails_out = []
    for biz in available:
        be = BatchEmail(batch_id=batch.id, business_id=biz.id)
        db.add(be)
        biz_city = (await db.execute(select(City.name).where(City.id == biz.city_id))).scalar()
        biz_cat = (await db.execute(select(Category.name).where(Category.id == biz.category_id))).scalar()
        batch_emails_out.append({
            "business_name": biz.name, "email": biz.email,
            "city": biz_city, "category": biz_cat,
        })

    db.add(CreditTransaction(
        company_id=company.id,
        amount=-actual_cost,
        type="purchase",
        description=f"Purchased {actual_size} emails: {label}",
    ))

    await db.commit()
    return {
        "batch_id": batch.id,
        "batch_size": actual_size,
        "cost": actual_cost,
        "remaining_credits": company.credit_balance,
        "emails": batch_emails_out,
    }


@router.get("/my-batches", response_model=list[BatchOut])
async def my_batches(
    company: Company = Depends(require_approved),
    db: AsyncSession = Depends(get_db),
):
    result = await db.execute(
        select(
            EmailBatch,
            Category.name.label("cat_name"),
            City.name.label("city_name"),
        )
        .outerjoin(Category, EmailBatch.category_id == Category.id)
        .outerjoin(City, EmailBatch.city_id == City.id)
        .where(EmailBatch.company_id == company.id)
        .order_by(EmailBatch.purchased_at.desc())
    )
    return [
        BatchOut(
            id=b.id,
            label=b.label,
            category_name=cat_name,
            city_name=city_name,
            batch_size=b.batch_size,
            price_paid=b.price_paid,
            purchased_at=b.purchased_at,
        )
        for b, cat_name, city_name in result.all()
    ]


@router.get("/my-batches/{batch_id}/emails", response_model=list[BatchEmailOut])
async def batch_emails(
    batch_id: int,
    company: Company = Depends(require_approved),
    db: AsyncSession = Depends(get_db),
):
    from models import City

    batch_q = await db.execute(
        select(EmailBatch).where(EmailBatch.id == batch_id, EmailBatch.company_id == company.id)
    )
    if not batch_q.scalar_one_or_none():
        raise HTTPException(status_code=404, detail="Batch not found")

    result = await db.execute(
        select(
            BatchEmail.id, Business.name, Business.email,
            City.name.label("city_name"), Category.name.label("cat_name"),
        )
        .join(Business, BatchEmail.business_id == Business.id)
        .join(City, Business.city_id == City.id)
        .join(Category, Business.category_id == Category.id)
        .where(BatchEmail.batch_id == batch_id)
    )
    return [
        BatchEmailOut(id=beid, business_name=bname, email=bemail, city=cname, category=catname)
        for beid, bname, bemail, cname, catname in result.all()
    ]


@router.post("/send-email")
async def send_email(
    req: SendEmailRequest,
    company: Company = Depends(require_approved),
    db: AsyncSession = Depends(get_db),
):
    if not company.smtp_host or not company.smtp_user or not company.smtp_pass:
        raise HTTPException(status_code=400, detail="SMTP not configured. Go to Settings to set up your email.")
    if not company.smtp_enabled:
        raise HTTPException(status_code=400, detail="SMTP is disabled. Enable it in Settings before sending.")

    premium = _is_premium(company)
    if premium:
        await _reset_daily_sends_if_needed(db, company)
        remaining = company.daily_send_limit - company.daily_sends_used
        if remaining <= 0:
            raise HTTPException(status_code=429, detail="Daily send limit reached. Resets tomorrow.")
        if len(req.batch_email_ids) > remaining:
            raise HTTPException(status_code=429, detail=f"Daily limit: {remaining} sends remaining today.")

    valid_ids = await db.execute(
        select(BatchEmail.id)
        .join(EmailBatch, BatchEmail.batch_id == EmailBatch.id)
        .where(EmailBatch.company_id == company.id, BatchEmail.id.in_(req.batch_email_ids))
    )
    valid = [row[0] for row in valid_ids.all()]
    if not valid:
        raise HTTPException(status_code=400, detail="No valid batch emails found")

    sent_records = []
    for beid in valid:
        se = SentEmail(
            company_id=company.id,
            batch_email_id=beid,
            subject=req.subject,
            body=req.body,
            status="pending",
        )
        db.add(se)
        sent_records.append(se)

    if premium:
        company.daily_sends_used += len(sent_records)

    await db.commit()
    await queue_emails(db, sent_records)
    return {"queued": len(sent_records)}


@router.get("/sent-history", response_model=list[SentEmailOut])
async def sent_history(
    page: int = Query(1, ge=1),
    per_page: int = Query(20, ge=1, le=100),
    company: Company = Depends(require_approved),
    db: AsyncSession = Depends(get_db),
):
    result = await db.execute(
        select(SentEmail, Business.email.label("recipient"))
        .join(BatchEmail, SentEmail.batch_email_id == BatchEmail.id)
        .join(Business, BatchEmail.business_id == Business.id)
        .where(SentEmail.company_id == company.id)
        .order_by(SentEmail.sent_at.desc())
        .offset((page - 1) * per_page)
        .limit(per_page)
    )
    return [
        SentEmailOut(
            id=se.id, recipient_email=recip, subject=se.subject,
            sent_at=se.sent_at, status=se.status,
        )
        for se, recip in result.all()
    ]


@router.get("/smtp-settings", response_model=SmtpSettingsOut)
async def get_smtp_settings(
    company: Company = Depends(require_approved),
):
    return SmtpSettingsOut(
        smtp_host=company.smtp_host,
        smtp_port=company.smtp_port,
        smtp_user=company.smtp_user,
        smtp_from_email=company.smtp_from_email,
        smtp_from_name=company.smtp_from_name,
        smtp_enabled=company.smtp_enabled,
        has_password=bool(company.smtp_pass),
    )


@router.put("/smtp-settings", response_model=SmtpSettingsOut)
async def save_smtp_settings(
    req: SmtpSettingsIn,
    company: Company = Depends(require_approved),
    db: AsyncSession = Depends(get_db),
):
    company.smtp_host = req.smtp_host
    company.smtp_port = req.smtp_port
    company.smtp_user = req.smtp_user
    company.smtp_from_email = req.smtp_from_email
    company.smtp_from_name = req.smtp_from_name or company.name
    company.smtp_enabled = req.smtp_enabled
    if req.smtp_pass:
        company.smtp_pass = req.smtp_pass
    await db.commit()
    return SmtpSettingsOut(
        smtp_host=company.smtp_host,
        smtp_port=company.smtp_port,
        smtp_user=company.smtp_user,
        smtp_from_email=company.smtp_from_email,
        smtp_from_name=company.smtp_from_name,
        smtp_enabled=company.smtp_enabled,
        has_password=bool(company.smtp_pass),
    )


@router.post("/smtp-test")
async def test_smtp(
    req: SmtpTestRequest,
    company: Company = Depends(require_approved),
):
    if not company.smtp_host or not company.smtp_user or not company.smtp_pass:
        raise HTTPException(status_code=400, detail="SMTP settings not configured. Save your settings first.")
    if not company.smtp_enabled:
        raise HTTPException(status_code=400, detail="SMTP is disabled. Enable the toggle and save before testing.")

    ok, error = await send_test_email(
        host=company.smtp_host,
        port=company.smtp_port or 587,
        user=company.smtp_user,
        password=company.smtp_pass,
        from_email=company.smtp_from_email or company.smtp_user,
        from_name=company.smtp_from_name or company.name,
        to_email=req.to_email,
    )
    if ok:
        return {"detail": f"Test email sent successfully to {req.to_email}"}
    raise HTTPException(status_code=400, detail=f"SMTP test failed: {error}")


def _mask_email(email: str) -> str:
    if "@" not in email:
        return "***"
    local, domain = email.split("@", 1)
    if len(local) <= 2:
        masked_local = local[0] + "***"
    else:
        masked_local = local[0] + "***" + local[-1]
    return f"{masked_local}@{domain}"
