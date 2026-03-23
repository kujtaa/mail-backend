import json
import os
from datetime import datetime
from fastapi import APIRouter, Depends, HTTPException, Query
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy import select, func, distinct, or_

from db import get_db
from models import (
    Business, Category, City, Company, EmailBatch, BatchEmail, CreditTransaction, SentEmail,
    UnsubscribedEmail,
)
from schemas import (
    AddCreditsRequest, CompanyAdmin, TransactionOut,
    ScrapeRequest, EmailPreview, SetSourcesRequest,
)
from dependencies import require_admin
from scrape_jobs import create_job, get_job, get_all_jobs, enqueue_job

router = APIRouter(prefix="/admin", tags=["Admin"])

_BASE_DIR = os.path.dirname(os.path.dirname(__file__))
SEARCH_CH_URL = "https://www.search.ch/index.en.html"

def _load_json(filename):
    with open(os.path.join(_BASE_DIR, filename)) as f:
        return json.load(f)


@router.get("/companies", response_model=list[CompanyAdmin])
async def list_companies(
    _admin: Company = Depends(require_admin),
    db: AsyncSession = Depends(get_db),
):
    result = await db.execute(select(Company).order_by(Company.created_at.desc()))
    companies = result.scalars().all()

    out = []
    for c in companies:
        batches_q = await db.execute(
            select(func.count(EmailBatch.id)).where(EmailBatch.company_id == c.id)
        )
        emails_q = await db.execute(
            select(func.count(BatchEmail.id))
            .join(EmailBatch, BatchEmail.batch_id == EmailBatch.id)
            .where(EmailBatch.company_id == c.id)
        )
        out.append(CompanyAdmin(
            id=c.id, name=c.name, email=c.email,
            credit_balance=c.credit_balance, is_admin=c.is_admin,
            is_approved=c.is_approved,
            plan=c.plan, plan_expires_at=c.plan_expires_at,
            daily_send_limit=c.daily_send_limit,
            allowed_sources=c.get_allowed_sources(),
            batches_count=batches_q.scalar(),
            total_purchased_emails=emails_q.scalar(),
            created_at=c.created_at,
        ))
    return out


@router.post("/add-credits")
async def add_credits(
    req: AddCreditsRequest,
    _admin: Company = Depends(require_admin),
    db: AsyncSession = Depends(get_db),
):
    result = await db.execute(select(Company).where(Company.id == req.company_id))
    company = result.scalar_one_or_none()
    if not company:
        raise HTTPException(status_code=404, detail="Company not found")

    company.credit_balance += req.amount
    db.add(CreditTransaction(
        company_id=company.id,
        amount=req.amount,
        type="topup",
        description=req.description or f"Admin credit topup: {req.amount}",
    ))
    await db.commit()
    return {"company_id": company.id, "new_balance": company.credit_balance}


@router.post("/approve/{company_id}")
async def approve_company(
    company_id: int,
    _admin: Company = Depends(require_admin),
    db: AsyncSession = Depends(get_db),
):
    result = await db.execute(select(Company).where(Company.id == company_id))
    company = result.scalar_one_or_none()
    if not company:
        raise HTTPException(status_code=404, detail="Company not found")
    company.is_approved = True
    await db.commit()
    return {"detail": f"Company '{company.name}' approved"}


@router.post("/reject/{company_id}")
async def reject_company(
    company_id: int,
    _admin: Company = Depends(require_admin),
    db: AsyncSession = Depends(get_db),
):
    result = await db.execute(select(Company).where(Company.id == company_id))
    company = result.scalar_one_or_none()
    if not company:
        raise HTTPException(status_code=404, detail="Company not found")
    if company.is_admin:
        raise HTTPException(status_code=400, detail="Cannot reject admin account")
    company.is_approved = False
    await db.commit()
    return {"detail": f"Company '{company.name}' approval revoked"}


@router.get("/all-emails")
async def all_emails(
    category: str = Query("all"),
    city: str = Query("all"),
    search: str = Query(""),
    page: int = Query(1, ge=1),
    per_page: int = Query(20, ge=1, le=100),
    _admin: Company = Depends(require_admin),
    db: AsyncSession = Depends(get_db),
):
    query = (
        select(Business.id, Business.name, Business.email,
               City.name.label("city_name"), Category.name.label("cat_name"))
        .join(City, Business.city_id == City.id)
        .join(Category, Business.category_id == Category.id)
        .where(Business.email.isnot(None), Business.email.contains("@"))
    )
    if category != "all":
        query = query.where(Category.name == category)
    if city != "all":
        query = query.where(City.name == city)
    if search:
        query = query.where(
            or_(
                Business.name.ilike(f"%{search}%"),
                Business.email.ilike(f"%{search}%"),
            )
        )

    count_q = await db.execute(select(func.count()).select_from(query.subquery()))
    total = count_q.scalar()

    query = query.order_by(Business.name).offset((page - 1) * per_page).limit(per_page)
    result = await db.execute(query)
    return {
        "total": total,
        "emails": [
            {"id": bid, "business_name": bname, "email": bemail, "city": cname, "category": catname}
            for bid, bname, bemail, cname, catname in result.all()
        ],
    }


@router.get("/filter-options")
async def filter_options(
    _admin: Company = Depends(require_admin),
    db: AsyncSession = Depends(get_db),
):
    city_q = await db.execute(
        select(City.name).join(Business, Business.city_id == City.id)
        .group_by(City.name).order_by(City.name)
    )
    cat_q = await db.execute(
        select(Category.name).join(Business, Business.category_id == Category.id)
        .group_by(Category.name).order_by(Category.name)
    )
    return {
        "cities": [r[0] for r in city_q.all()],
        "categories": [r[0] for r in cat_q.all()],
    }


@router.get("/scrape-options")
async def scrape_options(
    source: str = Query("local.ch"),
    _admin: Company = Depends(require_admin),
    db: AsyncSession = Depends(get_db),
):
    _source_files = {
        "gelbeseiten.de": ("cities_de.json", "categories_de.json"),
        "herold.at":      ("cities_at.json", "categories_at.json"),
        "proff.no":       ("cities_no.json", "categories_no.json"),
        "proff.dk":       ("cities_dk.json", "categories_dk.json"),
    }
    cities_file, cats_file = _source_files.get(source, ("cities.json", "categories.json"))
    cities = _load_json(cities_file)
    categories = _load_json(cats_file)

    result = await db.execute(
        select(City.name, Category.name)
        .join(Business, Business.city_id == City.id)
        .join(Category, Business.category_id == Category.id)
        .where(Business.source == source)
        .group_by(City.name, Category.name)
    )
    scraped_combos = [{"city": c, "category": cat} for c, cat in result.all()]

    return {
        "cities": cities,
        "categories": categories,
        "scraped": scraped_combos,
    }


@router.get("/proxies")
async def get_proxies(_admin: Company = Depends(require_admin)):
    from scraper import _load_proxies
    proxies = _load_proxies()
    return {
        "count": len(proxies),
        "proxies": [_mask_proxy(p) for p in proxies],
    }


@router.post("/proxies")
async def save_proxies(
    proxies: list[str],
    _admin: Company = Depends(require_admin),
):
    from scraper import reload_proxies
    proxy_file = os.path.join(_BASE_DIR, "proxies.txt")
    lines = [p.strip() for p in proxies if p.strip() and not p.startswith("#")]
    with open(proxy_file, "w") as f:
        f.write("# Proxy list — managed via admin panel\n")
        for line in lines:
            f.write(line + "\n")
    reload_proxies()
    return {"count": len(lines), "detail": f"Saved {len(lines)} proxies"}


def _mask_proxy(proxy: str) -> str:
    if "@" in proxy:
        scheme_and_creds, rest = proxy.rsplit("@", 1)
        scheme = scheme_and_creds.split("://")[0] if "://" in scheme_and_creds else "http"
        return f"{scheme}://***@{rest}"
    return proxy


@router.post("/trigger-scrape")
async def trigger_scrape(
    req: ScrapeRequest,
    _admin: Company = Depends(require_admin),
):
    search_term = req.category
    city_slug = req.city

    _source_cat_files = {
        "gelbeseiten.de": "categories_de.json",
        "herold.at":      "categories_at.json",
        "proff.no":       "categories_no.json",
        "proff.dk":       "categories_dk.json",
    }
    _source_city_files = {
        "herold.at":  "cities_at.json",
        "proff.no":   "cities_no.json",
        "proff.dk":   "cities_dk.json",
    }

    if req.source in _source_cat_files:
        cats = _load_json(_source_cat_files[req.source])
        match = next((c for c in cats if c["name"] == req.category), None)
        if match:
            search_term = match.get("slug") or match.get("search_term") or req.category

    if req.source in _source_city_files:
        city_json = _load_json(_source_city_files[req.source])
        city_match = next((c for c in city_json if c["name"] == req.city), None)
        if city_match:
            city_slug = city_match.get("slug", req.city)

    job_id = create_job(req.city, req.category, source=req.source)
    await enqueue_job(job_id, city_slug, search_term, source=req.source, db_category=req.category)
    return {"job_id": job_id, "status": "queued"}


@router.get("/scrape-jobs")
async def list_scrape_jobs(
    _admin: Company = Depends(require_admin),
):
    return get_all_jobs()


@router.get("/scrape-jobs/{job_id}")
async def get_scrape_job(
    job_id: str,
    _admin: Company = Depends(require_admin),
):
    job = get_job(job_id)
    if not job:
        raise HTTPException(status_code=404, detail="Job not found")
    return job


# ── No-Website Businesses ──────────────────────────────────────

@router.get("/no-website-businesses")
async def no_website_businesses(
    city: str = Query("all"),
    category: str = Query("all"),
    search: str = Query(""),
    page: int = Query(1, ge=1),
    per_page: int = Query(20, ge=1, le=100),
    _admin: Company = Depends(require_admin),
    db: AsyncSession = Depends(get_db),
):
    query = (
        select(
            Business.id, Business.name, Business.phone, Business.email,
            Business.address, Business.website,
            City.name.label("city_name"), Category.name.label("cat_name"),
        )
        .join(City, Business.city_id == City.id)
        .join(Category, Business.category_id == Category.id)
        .where(
            or_(
                Business.website.is_(None),
                Business.website == "",
                Business.website == SEARCH_CH_URL,
            )
        )
    )
    if city != "all":
        query = query.where(City.name == city)
    if category != "all":
        query = query.where(Category.name == category)
    if search:
        query = query.where(
            or_(
                Business.name.ilike(f"%{search}%"),
                Business.email.ilike(f"%{search}%"),
                Business.phone.ilike(f"%{search}%"),
            )
        )

    count_q = await db.execute(
        select(func.count()).select_from(query.subquery())
    )
    total = count_q.scalar()

    query = query.order_by(Business.name).offset((page - 1) * per_page).limit(per_page)
    result = await db.execute(query)

    return {
        "total": total,
        "businesses": [
            {
                "id": bid, "name": bname, "phone": phone, "email": bemail,
                "address": addr, "website": website,
                "city": cname, "category": catname,
            }
            for bid, bname, phone, bemail, addr, website, cname, catname in result.all()
        ],
    }


@router.get("/transactions", response_model=list[TransactionOut])
async def all_transactions(
    _admin: Company = Depends(require_admin),
    db: AsyncSession = Depends(get_db),
):
    result = await db.execute(
        select(CreditTransaction, Company.name.label("company_name"))
        .join(Company, CreditTransaction.company_id == Company.id)
        .order_by(CreditTransaction.created_at.desc())
    )
    return [
        TransactionOut(
            id=t.id, company_id=t.company_id, company_name=cname,
            amount=t.amount, type=t.type, description=t.description,
            created_at=t.created_at,
        )
        for t, cname in result.all()
    ]


@router.get("/unscraped")
async def unscraped_combos(
    source: str = Query("local.ch"),
    _admin: Company = Depends(require_admin),
    db: AsyncSession = Depends(get_db),
):
    _source_files = {
        "gelbeseiten.de": ("cities_de.json", "categories_de.json"),
        "herold.at":      ("cities_at.json", "categories_at.json"),
        "proff.no":       ("cities_no.json", "categories_no.json"),
        "proff.dk":       ("cities_dk.json", "categories_dk.json"),
    }
    cities_file, cats_file = _source_files.get(source, ("cities.json", "categories.json"))
    cities = _load_json(cities_file)
    categories = _load_json(cats_file)

    result = await db.execute(
        select(City.name, Category.name)
        .join(Business, Business.city_id == City.id)
        .join(Category, Business.category_id == Category.id)
        .where(Business.source == source)
        .group_by(City.name, Category.name)
    )
    scraped = {(c, cat) for c, cat in result.all()}

    city_names = [c["name"] for c in cities]
    cat_names = [c["name"] for c in categories]
    unscraped = [
        {"city": city, "category": cat}
        for city in city_names
        for cat in cat_names
        if (city, cat) not in scraped
    ]

    return {
        "total_combos": len(city_names) * len(cat_names),
        "scraped_count": len(scraped),
        "unscraped_count": len(unscraped),
        "unscraped": unscraped,
    }


@router.post("/set-plan")
async def set_plan(
    company_id: int = Query(...),
    plan: str = Query(...),
    daily_limit: int = Query(200),
    days: int = Query(30),
    _admin: Company = Depends(require_admin),
    db: AsyncSession = Depends(get_db),
):
    from datetime import timedelta
    result = await db.execute(select(Company).where(Company.id == company_id))
    company = result.scalar_one_or_none()
    if not company:
        raise HTTPException(status_code=404, detail="Company not found")

    company.plan = plan
    if plan == "premium":
        company.plan_expires_at = datetime.utcnow() + timedelta(days=days)
        company.daily_send_limit = daily_limit
    else:
        company.plan_expires_at = None
        company.daily_send_limit = 0

    company.daily_sends_used = 0
    await db.commit()
    return {"detail": f"Plan set to '{plan}' for {company.name}", "expires_at": str(company.plan_expires_at)}


@router.delete("/companies/{company_id}")
async def delete_company(
    company_id: int,
    _admin: Company = Depends(require_admin),
    db: AsyncSession = Depends(get_db),
):
    result = await db.execute(select(Company).where(Company.id == company_id))
    company = result.scalar_one_or_none()
    if not company:
        raise HTTPException(status_code=404, detail="Company not found")
    if company.is_admin:
        raise HTTPException(status_code=400, detail="Cannot delete admin account")

    name = company.name

    batch_ids_q = await db.execute(
        select(EmailBatch.id).where(EmailBatch.company_id == company_id)
    )
    batch_ids = [r[0] for r in batch_ids_q.all()]

    if batch_ids:
        be_ids_q = await db.execute(
            select(BatchEmail.id).where(BatchEmail.batch_id.in_(batch_ids))
        )
        be_ids = [r[0] for r in be_ids_q.all()]

        if be_ids:
            await db.execute(
                SentEmail.__table__.delete().where(SentEmail.batch_email_id.in_(be_ids))
            )
            await db.execute(
                BatchEmail.__table__.delete().where(BatchEmail.id.in_(be_ids))
            )

        await db.execute(
            EmailBatch.__table__.delete().where(EmailBatch.id.in_(batch_ids))
        )

    await db.execute(
        CreditTransaction.__table__.delete().where(CreditTransaction.company_id == company_id)
    )

    await db.delete(company)
    await db.commit()
    return {"detail": f"Company '{name}' permanently deleted"}


@router.post("/set-sources")
async def set_company_sources(
    req: SetSourcesRequest,
    _admin: Company = Depends(require_admin),
    db: AsyncSession = Depends(get_db),
):
    valid_sources = {"local.ch", "gelbeseiten.de", "herold.at", "proff.no", "proff.dk"}
    invalid = set(req.sources) - valid_sources
    if invalid:
        raise HTTPException(status_code=400, detail=f"Invalid sources: {invalid}")

    result = await db.execute(select(Company).where(Company.id == req.company_id))
    company = result.scalar_one_or_none()
    if not company:
        raise HTTPException(status_code=404, detail="Company not found")

    company.set_allowed_sources(req.sources)
    await db.commit()
    return {"detail": f"Sources updated for '{company.name}'", "sources": company.get_allowed_sources()}


@router.get("/unsubscribed")
async def list_unsubscribed(
    search: str = Query(""),
    page: int = Query(1, ge=1),
    per_page: int = Query(20, ge=1, le=100),
    _admin: Company = Depends(require_admin),
    db: AsyncSession = Depends(get_db),
):
    query = select(UnsubscribedEmail).order_by(UnsubscribedEmail.unsubscribed_at.desc())
    if search:
        query = query.where(UnsubscribedEmail.email.ilike(f"%{search}%"))

    count_q = await db.execute(
        select(func.count()).select_from(query.subquery())
    )
    total = count_q.scalar()

    query = query.offset((page - 1) * per_page).limit(per_page)
    result = await db.execute(query)
    rows = result.scalars().all()

    biz_ids = [r.business_id for r in rows if r.business_id]
    biz_map = {}
    if biz_ids:
        biz_q = await db.execute(
            select(Business.id, Business.name, City.name.label("city_name"), Category.name.label("cat_name"))
            .join(City, Business.city_id == City.id)
            .join(Category, Business.category_id == Category.id)
            .where(Business.id.in_(biz_ids))
        )
        for bid, bname, cname, catname in biz_q.all():
            biz_map[bid] = {"business_name": bname, "city": cname, "category": catname}

    items = []
    for r in rows:
        info = biz_map.get(r.business_id, {})
        items.append({
            "id": r.id,
            "email": r.email,
            "business_name": info.get("business_name"),
            "city": info.get("city"),
            "category": info.get("category"),
            "unsubscribed_at": r.unsubscribed_at.isoformat() if r.unsubscribed_at else None,
        })

    return {"total": total, "items": items}


@router.delete("/unsubscribed/{unsub_id}")
async def remove_unsubscribed(
    unsub_id: int,
    _admin: Company = Depends(require_admin),
    db: AsyncSession = Depends(get_db),
):
    result = await db.execute(
        select(UnsubscribedEmail).where(UnsubscribedEmail.id == unsub_id)
    )
    unsub = result.scalar_one_or_none()
    if not unsub:
        raise HTTPException(status_code=404, detail="Record not found")
    email = unsub.email
    await db.delete(unsub)
    await db.commit()
    return {"detail": f"'{email}' removed from unsubscribe list"}
