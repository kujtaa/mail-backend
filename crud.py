import logging
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy import select, and_, or_
from models import Business, City, Category

log = logging.getLogger("crud")

MAX_WEBSITE_LEN = 500
JUNK_DOMAINS = {"translate.google", "search.ch/index", "facebook.com", "instagram.com", "twitter.com", "linkedin.com"}


def _clean_website(url: str | None) -> str | None:
    if not url:
        return None
    if len(url) > MAX_WEBSITE_LEN:
        return None
    if any(d in url.lower() for d in JUNK_DOMAINS):
        return None
    return url


async def get_or_create_city(db: AsyncSession, name: str) -> City:
    result = await db.execute(select(City).where(City.name == name))
    city = result.scalar_one_or_none()
    if not city:
        city = City(name=name)
        db.add(city)
        await db.flush()
    return city


async def get_or_create_category(db: AsyncSession, name: str) -> Category:
    result = await db.execute(select(Category).where(Category.name == name))
    cat = result.scalar_one_or_none()
    if not cat:
        cat = Category(name=name)
        db.add(cat)
        await db.flush()
    return cat


async def save_scraped_data(
    db: AsyncSession,
    city_name: str,
    category_name: str,
    businesses: list[dict],
    source: str = "local.ch",
) -> int:
    city = await get_or_create_city(db, city_name)
    category = await get_or_create_category(db, category_name)
    saved = 0

    for biz in businesses:
        name = biz.get("name")
        if not name:
            continue

        website = _clean_website(biz.get("website"))

        filters = [
            Business.city_id == city.id,
            Business.category_id == category.id,
            Business.name == name,
        ]
        dup_conditions = []
        if biz.get("phone"):
            dup_conditions.append(Business.phone == biz["phone"])
        if biz.get("email"):
            dup_conditions.append(Business.email == biz["email"])

        if dup_conditions:
            filters.append(or_(*dup_conditions))

        result = await db.execute(select(Business).where(and_(*filters)).limit(1))
        existing = result.scalar_one_or_none()

        if existing:
            existing.phone = biz.get("phone") or existing.phone
            existing.email = biz.get("email") or existing.email
            existing.address = biz.get("address") or existing.address
            existing.website = website or existing.website
            existing.source = source
        else:
            db.add(Business(
                name=name,
                phone=biz.get("phone"),
                email=biz.get("email"),
                address=biz.get("address"),
                website=website,
                city_id=city.id,
                category_id=category.id,
                source=source,
            ))
            saved += 1

    await db.commit()
    return saved
