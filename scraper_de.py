"""
Scraper for gelbeseiten.de (German Yellow Pages).

Extracts business data from listing pages (name, phone, email, address)
and visits detail pages only to fetch the website URL.
"""

import asyncio
import json
import random
import re
import logging
import aiohttp
from bs4 import BeautifulSoup
from random_user_agent.user_agent import UserAgent
from random_user_agent.params import SoftwareName, OperatingSystem

log = logging.getLogger("scraper_de")

software_names = [SoftwareName.CHROME.value]
os_names = [OperatingSystem.WINDOWS.value, OperatingSystem.LINUX.value]
user_agent_rotator = UserAgent(software_names=software_names, operating_systems=os_names, limit=100)

BASE_URL = "https://www.gelbeseiten.de"

MAX_RETRIES = 4


def _random_headers() -> dict:
    return {
        "User-Agent": user_agent_rotator.get_random_user_agent(),
        "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
        "Accept-Language": "de-DE,de;q=0.9,en;q=0.5",
        "Accept-Encoding": "gzip, deflate, br",
        "DNT": "1",
        "Connection": "keep-alive",
        "Upgrade-Insecure-Requests": "1",
        "Sec-Fetch-Dest": "document",
        "Sec-Fetch-Mode": "navigate",
        "Sec-Fetch-Site": "none",
        "Sec-Fetch-User": "?1",
    }


async def _fetch(
    session: aiohttp.ClientSession,
    url: str,
    semaphore: asyncio.Semaphore,
) -> str | None:
    from scraper import _get_proxy, _block_proxy, _get_delay

    min_delay, max_delay = _get_delay()

    for attempt in range(MAX_RETRIES):
        async with semaphore:
            await asyncio.sleep(random.uniform(min_delay, max_delay) + (attempt * 1.5))
            proxy = _get_proxy()
            try:
                async with session.get(
                    url,
                    headers=_random_headers(),
                    proxy=proxy,
                    timeout=aiohttp.ClientTimeout(total=25),
                ) as resp:
                    if resp.status == 200:
                        return await resp.text()
                    if resp.status in (429, 403):
                        _block_proxy(proxy, 120)
                        await asyncio.sleep(random.uniform(2, 5))
                        continue
                    if resp.status >= 500:
                        await asyncio.sleep(5 * (attempt + 1))
                        continue
                    return None
            except (aiohttp.ClientError, asyncio.TimeoutError):
                _block_proxy(proxy, 60)
                await asyncio.sleep(3 * (attempt + 1))
            except Exception:
                return None
    log.warning("Failed after %d retries: %s", MAX_RETRIES, url)
    return None


def _extract_listings_from_page(html: str) -> list[dict]:
    """Extract business data directly from a listing page's <article> elements."""
    soup = BeautifulSoup(html, "html.parser")
    results = []

    for article in soup.find_all("article", class_="mod-Treffer"):
        biz: dict = {}

        h2 = article.find("h2", class_="mod-Treffer__name")
        biz["name"] = h2.get_text(strip=True) if h2 else None

        link = article.find("a", href=True)
        biz["_detail_url"] = link["href"] if link else None

        addr_div = article.find("div", class_="mod-AdresseKompakt__adress-text")
        if addr_div:
            raw = addr_div.get_text(" ", strip=True)
            raw = re.sub(r"\s*\d+[,.]?\d*\s*km\s*$", "", raw)
            raw = re.sub(r"\s*\([^)]*\)\s*$", "", raw).strip()
            biz["address"] = raw

        chat_btn = article.find("button", class_=re.compile(r"contains-icon-chat"))
        if chat_btn and chat_btn.get("data-parameters"):
            try:
                params = json.loads(chat_btn["data-parameters"])
                generic = (
                    params.get("inboxConfig", {})
                    .get("organizationQuery", {})
                    .get("generic", {})
                )
                phones = generic.get("phones", [])
                if phones:
                    biz["phone"] = phones[0]
                email = generic.get("email")
                if email:
                    biz["email"] = email
                if not biz.get("address"):
                    street = generic.get("street", "")
                    zipcode = generic.get("zip", "")
                    city = generic.get("city", "")
                    if street:
                        biz["address"] = f"{street}, {zipcode} {city}".strip(", ")
            except (json.JSONDecodeError, KeyError):
                pass

        if not biz.get("phone"):
            phone_div = article.find("div", class_=re.compile(r"telefon|phone", re.I))
            if phone_div:
                biz["phone"] = phone_div.get_text(strip=True)

        if biz.get("name"):
            results.append(biz)

    return results


def _extract_website_from_detail(html: str) -> str | None:
    """Extract the website URL from a detail page."""
    soup = BeautifulSoup(html, "html.parser")

    skip_domains = (
        "gelbeseiten.de", "golocal.de", "google.com", "facebook.com",
        "instagram.com", "twitter.com", "linkedin.com", "youtube.com",
        "tiktok.com", "apple.com", "bfb.de",
    )

    for a_tag in soup.find_all("a", href=True):
        href = a_tag.get("href", "")
        text = a_tag.get_text(strip=True).lower()
        if ("website" in text or "webseite" in text) and href.startswith("http"):
            if not any(d in href for d in skip_domains):
                return href

    for a_tag in soup.find_all("a", href=True):
        href = a_tag.get("href", "")
        if href.startswith("http") and not any(d in href for d in skip_domains):
            parent_classes = " ".join(a_tag.parent.get("class", []))
            if "website" in parent_classes.lower() or "webseite" in parent_classes.lower():
                return href

    return None


async def scrape_category(city: str, category: str) -> list[dict]:
    from scraper import _get_concurrency, _load_proxies

    results = []
    seen_names: set[str] = set()
    concurrency = _get_concurrency()
    semaphore = asyncio.Semaphore(concurrency)
    proxy_count = len(_load_proxies())

    log.info("Scraping DE %s/%s — concurrency=%d, proxies=%d", city, category, concurrency, proxy_count)

    search_term = category.lower().replace(" ", "+")
    city_slug = city.lower()

    connector = aiohttp.TCPConnector(limit=concurrency + 5, force_close=True)
    jar = aiohttp.CookieJar(unsafe=True)

    async with aiohttp.ClientSession(connector=connector, cookie_jar=jar) as session:
        page = 1
        empty_pages = 0

        while empty_pages < 2:
            url = f"{BASE_URL}/suche/{search_term}/{city_slug}"
            if page > 1:
                url += f"?page={page}"

            html = await _fetch(session, url, semaphore)
            if not html:
                break

            listings = _extract_listings_from_page(html)
            if not listings:
                empty_pages += 1
                page += 1
                continue

            empty_pages = 0
            new_listings = []
            for biz in listings:
                key = biz.get("name", "")
                if key and key not in seen_names:
                    seen_names.add(key)
                    new_listings.append(biz)

            if not new_listings:
                break

            detail_urls = [
                b["_detail_url"] for b in new_listings
                if b.get("_detail_url") and b["_detail_url"].startswith("http")
            ]
            if concurrency > 1:
                tasks = [_fetch(session, u, semaphore) for u in detail_urls]
                detail_pages = await asyncio.gather(*tasks)
            else:
                detail_pages = []
                for u in detail_urls:
                    detail_pages.append(await _fetch(session, u, semaphore))

            url_to_website: dict[str, str | None] = {}
            for detail_url, detail_html in zip(detail_urls, detail_pages):
                if detail_html:
                    url_to_website[detail_url] = _extract_website_from_detail(detail_html)

            for biz in new_listings:
                detail = biz.pop("_detail_url", None)
                if detail:
                    biz["website"] = url_to_website.get(detail)
                results.append(biz)

            page += 1
            await asyncio.sleep(random.uniform(1, 3) if proxy_count >= 50 else random.uniform(3, 8))

    log.info("Finished DE %s/%s — %d results", city, category, len(results))
    return results
