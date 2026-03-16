import json
from fastapi import APIRouter, Depends, Query
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy import select, func, distinct

from db import get_db
from models import Business, City, Category
from schemas import ScrapeRequest, BusinessOut
from scraper import scrape_category
from crud import save_scraped_data, get_or_create_city, get_or_create_category
from cache import get_cached, set_cached, invalidate_cache

router = APIRouter(tags=["Scraper"])


@router.post("/scrape")
async def scrape(req: ScrapeRequest, db: AsyncSession = Depends(get_db)):
    city_result = await db.execute(select(City).where(City.name == req.city))
    city = city_result.scalar_one_or_none()
    cat_result = await db.execute(select(Category).where(Category.name == req.category))
    cat = cat_result.scalar_one_or_none()

    if city and cat:
        existing = await db.execute(
            select(Business).where(Business.city_id == city.id, Business.category_id == cat.id).limit(1)
        )
        if existing.scalar_one_or_none():
            result = await db.execute(
                select(Business).where(Business.city_id == city.id, Business.category_id == cat.id)
            )
            businesses = result.scalars().all()
            return {"source": "database", "count": len(businesses), "data": [
                {"name": b.name, "phone": b.phone, "email": b.email,
                 "address": b.address, "website": b.website}
                for b in businesses
            ]}

    scraped = await scrape_category(req.city, req.category)
    if scraped:
        saved = await save_scraped_data(db, req.city, req.category, scraped)
        invalidate_cache()
        return {"source": "scraper", "count": len(scraped), "saved": saved, "data": scraped}
    return {"source": "scraper", "count": 0, "data": []}


@router.get("/data")
async def get_data(
    city: str = Query(...),
    category: str = Query(...),
    db: AsyncSession = Depends(get_db),
):
    query = (
        select(Business, City.name.label("city_name"), Category.name.label("cat_name"))
        .join(City, Business.city_id == City.id)
        .join(Category, Business.category_id == Category.id)
        .where(City.name == city, Category.name == category)
    )
    result = await db.execute(query)
    rows = result.all()
    return [
        BusinessOut(
            id=b.id, name=b.name, phone=b.phone, email=b.email,
            address=b.address, website=b.website,
            city=city_name, category=cat_name,
        )
        for b, city_name, cat_name in rows
    ]


@router.get("/emails")
async def get_emails(
    category: str = Query("all"),
    db: AsyncSession = Depends(get_db),
):
    query = select(distinct(Business.email)).where(Business.email.isnot(None), Business.email.contains("@"))
    if category != "all":
        query = query.join(Category, Business.category_id == Category.id).where(Category.name == category)
    result = await db.execute(query)
    return [row[0] for row in result.all()]


@router.get("/total-scraped")
async def total_scraped(db: AsyncSession = Depends(get_db)):
    cached = get_cached("total_scraped")
    if cached is not None:
        return {"total": cached}
    result = await db.execute(select(func.count(distinct(Business.id))))
    total = result.scalar()
    set_cached("total_scraped", total)
    return {"total": total}


@router.get("/total-emails")
async def total_emails(db: AsyncSession = Depends(get_db)):
    cached = get_cached("total_emails")
    if cached is not None:
        return {"total": cached}
    result = await db.execute(
        select(func.count(distinct(Business.email)))
        .where(Business.email.isnot(None), Business.email.contains("@"))
    )
    total = result.scalar()
    set_cached("total_emails", total)
    return {"total": total}


@router.get("/category-counts")
async def category_counts(db: AsyncSession = Depends(get_db)):
    cached = get_cached("category_counts")
    if cached is not None:
        return cached
    result = await db.execute(
        select(Category.name, func.count(distinct(Business.email)))
        .join(Business, Business.category_id == Category.id)
        .where(Business.email.isnot(None), Business.email.contains("@"))
        .group_by(Category.name)
    )
    counts = {name: count for name, count in result.all()}
    set_cached("category_counts", counts)
    return counts


@router.get("/export_and_save")
async def export_and_save(db: AsyncSession = Depends(get_db)):
    result = await db.execute(
        select(Business, City.name.label("city_name"), Category.name.label("cat_name"))
        .join(City, Business.city_id == City.id)
        .join(Category, Business.category_id == Category.id)
    )
    rows = result.all()
    data = [
        {"id": b.id, "name": b.name, "phone": b.phone, "email": b.email,
         "address": b.address, "website": b.website, "city": cn, "category": catn}
        for b, cn, catn in rows
    ]
    with open("exported_data.json", "w") as f:
        json.dump(data, f, indent=2, default=str)
    return {"exported": len(data), "file": "exported_data.json"}


@router.post("/push-test-data")
async def push_test_data(db: AsyncSession = Depends(get_db)):
    test_businesses = [
        {"name": f"Test Biz {i}", "phone": f"+4100000000{i}",
         "email": f"test{i}@example.com", "address": "Test Street 1, Zurich",
         "website": f"https://testbiz{i}.ch"}
        for i in range(1, 6)
    ]
    saved = await save_scraped_data(db, "Zurich", "dev-test", test_businesses)
    invalidate_cache()
    return {"saved": saved, "data": test_businesses}
