import json
import os
from datetime import datetime
from fastapi import APIRouter, Depends, HTTPException, Query
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy import select, func, distinct, or_

from db import get_db
from models import (
    Business, Category, City, Company, EmailBatch, BatchEmail, CreditTransaction,
)
from schemas import (
    AddCreditsRequest, CompanyAdmin, TransactionOut,
    ScrapeRequest, EmailPreview,
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
    if source == "gelbeseiten.de":
        cities = _load_json("cities_de.json")
        categories = _load_json("categories_de.json")
    else:
        cities = _load_json("cities.json")
        categories = _load_json("categories.json")

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
    if req.source == "gelbeseiten.de":
        cats = _load_json("categories_de.json")
        match = next((c for c in cats if c["name"] == req.category), None)
        if match:
            search_term = match.get("search_term", req.category)

    job_id = create_job(req.city, req.category, source=req.source)
    await enqueue_job(job_id, req.city, search_term, source=req.source, db_category=req.category)
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
    if source == "gelbeseiten.de":
        cities = _load_json("cities_de.json")
        categories = _load_json("categories_de.json")
    else:
        cities = _load_json("cities.json")
        categories = _load_json("categories.json")

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
async def deactivate_company(
    company_id: int,
    _admin: Company = Depends(require_admin),
    db: AsyncSession = Depends(get_db),
):
    result = await db.execute(select(Company).where(Company.id == company_id))
    company = result.scalar_one_or_none()
    if not company:
        raise HTTPException(status_code=404, detail="Company not found")
    if company.is_admin:
        raise HTTPException(status_code=400, detail="Cannot deactivate admin account")

    company.credit_balance = 0
    company.hashed_password = "DEACTIVATED"
    await db.commit()
    return {"detail": f"Company '{company.name}' deactivated"}
