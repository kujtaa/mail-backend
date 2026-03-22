"""
Scraper for herold.at (Austrian Yellow Pages).

herold.at uses the Qwik SSR framework. Listing pages render all business data
(name, email, website, phone, address) directly in a <script type="qwik/json">
block — no separate detail-page visits required.

URL pattern:
  Listing:    https://www.herold.at/gelbe-seiten/{city-slug}/{category-slug}/
  Pagination: …/{category-slug}/seite/{N}/
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

log = logging.getLogger("scraper_at")

software_names = [SoftwareName.CHROME.value]
os_names = [OperatingSystem.WINDOWS.value, OperatingSystem.LINUX.value]
user_agent_rotator = UserAgent(software_names=software_names, operating_systems=os_names, limit=100)

BASE_URL = "https://www.herold.at"
MAX_RETRIES = 4

_SKIP_DOMAINS = (
    "herold.at", "google.", "facebook.", "instagram.", "youtube.",
    "xing.", "twitter.", "linkedin.", "tiktok.", "mktgcdn.", "accor.",
    "consentmanager", "baseline.", "sgtm.", "pagead2.", "dropinblog.",
    "sitegainer.", "lwadm.",
)

_EMAIL_RE = re.compile(r'^[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}$')
_PHONE_AT_RE = re.compile(r'^\+43[\s\d\-\/]{6,20}$|^0\d[\d\s\-\/]{6,20}$')


def _random_headers() -> dict:
    return {
        "User-Agent": user_agent_rotator.get_random_user_agent(),
        "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
        "Accept-Language": "de-AT,de;q=0.9,en;q=0.5",
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
            proxy_label = proxy.split("//")[-1] if proxy else "direct"
            try:
                async with session.get(
                    url,
                    headers=_random_headers(),
                    proxy=proxy,
                    timeout=aiohttp.ClientTimeout(total=25),
                ) as resp:
                    if resp.status == 200:
                        log.debug("OK 200 via %s: %s", proxy_label, url)
                        return await resp.text()
                    if resp.status in (429, 403):
                        log.warning("HTTP %d via %s (attempt %d/%d): %s",
                                    resp.status, proxy_label, attempt + 1, MAX_RETRIES, url)
                        _block_proxy(proxy, 120)
                        await asyncio.sleep(random.uniform(2, 5))
                        continue
                    if resp.status >= 500:
                        await asyncio.sleep(5 * (attempt + 1))
                        continue
                    return None
            except aiohttp.ClientHttpProxyError as e:
                if e.status == 407:
                    # Proxy authentication required — IP not whitelisted on provider.
                    # Don't block the proxy; rotate to another one instead.
                    log.debug("407 via %s — rotating proxy", proxy_label)
                    await asyncio.sleep(1)
                else:
                    log.warning("Proxy error via %s (attempt %d/%d): %s",
                                proxy_label, attempt + 1, MAX_RETRIES, e)
                    _block_proxy(proxy, 60)
                    await asyncio.sleep(3 * (attempt + 1))
            except (aiohttp.ClientError, asyncio.TimeoutError) as e:
                log.warning("Connection error via %s (attempt %d/%d): %s — %s",
                            proxy_label, attempt + 1, MAX_RETRIES, type(e).__name__, e)
                _block_proxy(proxy, 60)
                await asyncio.sleep(3 * (attempt + 1))
            except Exception as e:
                log.error("Unexpected error: %s — %s", type(e).__name__, e)
                return None

    log.warning("Failed after %d retries: %s", MAX_RETRIES, url)
    return None


def _extract_businesses_from_qwik(html: str) -> list[dict]:
    """
    Parse the Qwik JSON state block and extract business records.

    The Qwik state stores all serialised component data in a flat `objs` array.
    Business emails and websites appear as consecutive string values in this
    array close to the company name.  We walk the array and group strings into
    per-business buckets using the detail-link anchors found in the raw HTML as
    anchors (they appear in order).
    """
    # 1. Extract Qwik JSON state
    m = re.search(r'<script[^>]*type=["\']qwik/json["\'][^>]*>(.*?)</script>', html, re.DOTALL)
    if not m:
        return []

    try:
        state = json.loads(m.group(1))
    except json.JSONDecodeError:
        return []

    objs = state.get("objs", [])
    if not objs:
        return []

    # 2. Find detail-page paths in the HTML – they give us order + name slugs
    detail_links = re.findall(
        r'/gelbe-seiten/([a-z0-9_%-]+)/([A-Za-z0-9]+)/([a-z0-9%-]+)/',
        html,
    )
    # Each match is (city_slug, business_id, name_slug)
    unique_links: list[tuple[str, str, str]] = list(dict.fromkeys(detail_links))

    if not unique_links:
        return []

    # 3. Build lookup sets of all emails and external website URLs from objs
    #    Track their index so we can pair them with the nearest name.
    email_map: dict[int, str] = {}   # index → email
    website_map: dict[int, str] = {}  # index → url
    phone_map: dict[int, str] = {}   # index → phone
    name_map: dict[int, str] = {}    # index → company name candidate

    for idx, obj in enumerate(objs):
        if not isinstance(obj, str):
            continue
        s = obj.strip()
        if _EMAIL_RE.match(s):
            email_map[idx] = s
        elif s.startswith("http") and not any(d in s for d in _SKIP_DOMAINS) and len(s) < 250:
            website_map[idx] = s
        elif _PHONE_AT_RE.match(s):
            phone_map[idx] = s
        # Company name: reasonable length, contains a space or uppercase, not a URL slug
        elif (
            3 < len(s) < 120
            and not s.startswith("/")
            and not s.startswith("http")
            and (" " in s or s[0].isupper())
        ):
            name_map[idx] = s

    # 4. For each business (in order), find its email and website by scanning
    #    the objs window around the business name derived from the slug.
    results: list[dict] = []
    email_indices = sorted(email_map.keys())

    for city_slug, biz_id, name_slug in unique_links:
        # Derive a clean display name from the slug
        display_name = name_slug.replace("-", " ").title()

        # Find the best matching name in name_map (closest to any nearby email)
        biz: dict = {
            "name": display_name,
            "email": None,
            "website": None,
            "phone": None,
            "address": None,
        }

        # 4a. Find the email closest to this business (they appear in sequence)
        #     We pop email_indices from the front as we consume them.
        if email_indices:
            # Take the first available email; it corresponds to the current
            # business in the order they appear on the page.
            e_idx = email_indices.pop(0)
            biz["email"] = email_map[e_idx]

            # Look for a website within ±5 indices of the email
            for wi in range(max(0, e_idx - 6), min(len(objs), e_idx + 6)):
                if wi in website_map:
                    biz["website"] = website_map[wi]
                    break

            # Look for a phone within ±10 indices
            for pi in range(max(0, e_idx - 10), min(len(objs), e_idx + 10)):
                if pi in phone_map:
                    biz["phone"] = phone_map[pi]
                    break

            # Try to find a better display name from name_map near the email
            for ni in range(max(0, e_idx - 50), e_idx):
                if ni in name_map:
                    candidate = name_map[ni]
                    # Prefer candidates that look like company names
                    if len(candidate.split()) >= 1 and candidate[0].isupper():
                        biz["name"] = candidate

        # Even without an email, include the business if we at least have a website
        if biz["email"] or biz["website"]:
            results.append(biz)

    return results


def _extract_total_pages(html: str) -> int:
    """Extract total page count from the Qwik state."""
    # Look for pattern like "NNN zu {Category} in {City}" and pagination links
    page_links = re.findall(r'/seite/(\d+)/', html)
    if page_links:
        return max(int(p) for p in page_links)
    return 1


async def scrape_category(city: str, category: str) -> list[dict]:
    """
    Scrape herold.at for a given city slug and category slug.

    Args:
        city: herold.at city slug (e.g. "wien", "graz")
        category: herold.at category slug (e.g. "restaurant", "hotel")
    """
    from scraper import _get_concurrency, _load_proxies

    concurrency = _get_concurrency()
    semaphore = asyncio.Semaphore(concurrency)
    proxy_count = len(_load_proxies())

    log.info(
        "Scraping AT %s/%s — concurrency=%d, proxies=%d",
        city, category, concurrency, proxy_count,
    )

    results: list[dict] = []
    seen_names: set[str] = set()

    connector = aiohttp.TCPConnector(limit=concurrency + 5, force_close=True)
    jar = aiohttp.CookieJar(unsafe=True)

    async with aiohttp.ClientSession(connector=connector, cookie_jar=jar) as session:
        # Fetch first page to determine total pages
        url_p1 = f"{BASE_URL}/gelbe-seiten/{city}/{category}/"
        html = await _fetch(session, url_p1, semaphore)
        if not html:
            log.warning("No response for AT %s/%s", city, category)
            return []

        if "nicht gefunden" in html.lower():
            log.warning("AT %s/%s — category slug not found on herold.at", city, category)
            return []

        listings = _extract_businesses_from_qwik(html)
        total_pages = _extract_total_pages(html)

        log.info("AT %s/%s — page 1/%d → %d listings", city, category, total_pages, len(listings))

        for biz in listings:
            key = biz.get("name", "").lower()
            if key and key not in seen_names:
                seen_names.add(key)
                results.append(biz)

        # Fetch remaining pages concurrently in batches
        if total_pages > 1:
            pages = list(range(2, total_pages + 1))
            for batch_start in range(0, len(pages), concurrency):
                batch = pages[batch_start:batch_start + concurrency]
                tasks = [
                    _fetch(session, f"{BASE_URL}/gelbe-seiten/{city}/{category}/seite/{p}/", semaphore)
                    for p in batch
                ]
                pages_html = await asyncio.gather(*tasks)

                for page_num, page_html in zip(batch, pages_html):
                    if not page_html:
                        continue
                    page_listings = _extract_businesses_from_qwik(page_html)
                    log.debug("AT %s/%s page %d → %d listings", city, category, page_num, len(page_listings))
                    for biz in page_listings:
                        key = biz.get("name", "").lower()
                        if key and key not in seen_names:
                            seen_names.add(key)
                            results.append(biz)

                await asyncio.sleep(
                    random.uniform(0.5, 1.5) if proxy_count >= 100 else random.uniform(2, 5)
                )

    log.info("Finished AT %s/%s — %d results", city, category, len(results))
    return results
