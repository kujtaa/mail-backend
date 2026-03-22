"""
Scraper for proff.dk (Danish business directory).

Shares the same Next.js architecture as proff.no — identical data structure,
different domain and language parameter.

URL: https://www.proff.dk/_next/data/{buildId}/search.json?q={query}&geo={city}&lang=da&page={N}
"""

import asyncio
import json
import random
import re
import logging
import aiohttp
from random_user_agent.user_agent import UserAgent
from random_user_agent.params import SoftwareName, OperatingSystem

log = logging.getLogger("scraper_dk")

software_names = [SoftwareName.CHROME.value]
os_names = [OperatingSystem.WINDOWS.value, OperatingSystem.LINUX.value]
user_agent_rotator = UserAgent(software_names=software_names, operating_systems=os_names, limit=100)

BASE_URL = "https://www.proff.dk"
MAX_RETRIES = 4
MAX_PAGES = 20

_SKIP_DOMAINS = (
    "proff.dk", "google.", "facebook.", "instagram.", "youtube.",
    "xing.", "twitter.", "linkedin.", "tiktok.",
)


def _random_headers() -> dict:
    return {
        "User-Agent": user_agent_rotator.get_random_user_agent(),
        "Accept": "application/json, text/plain, */*",
        "Accept-Language": "da,en;q=0.5",
        "Accept-Encoding": "gzip, deflate, br",
        "DNT": "1",
        "Connection": "keep-alive",
        "Referer": BASE_URL + "/",
    }


async def _fetch(
    session: aiohttp.ClientSession,
    url: str,
    semaphore: asyncio.Semaphore,
) -> dict | None:
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
                        text = await resp.text()
                        return json.loads(text)
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


async def _get_build_id(session: aiohttp.ClientSession, semaphore: asyncio.Semaphore) -> str | None:
    from scraper import _get_proxy, _get_delay

    min_delay, max_delay = _get_delay()
    proxy = _get_proxy()
    try:
        async with semaphore:
            await asyncio.sleep(random.uniform(min_delay, max_delay))
            headers = {**_random_headers(), "Accept": "text/html,application/xhtml+xml"}
            async with session.get(
                BASE_URL,
                headers=headers,
                proxy=proxy,
                timeout=aiohttp.ClientTimeout(total=20),
            ) as resp:
                if resp.status == 200:
                    html = await resp.text()
                    m = re.search(r'"buildId"\s*:\s*"([^"]+)"', html)
                    if m:
                        return m.group(1)
    except Exception as e:
        log.warning("Could not fetch proff.dk build ID: %s", e)
    return None


def _extract_companies(data: dict) -> list[dict]:
    store = (
        data.get("pageProps", {})
        .get("hydrationData", {})
        .get("searchStore", {})
    )
    companies_data = store.get("companies", {})
    raw_list = companies_data.get("companies", [])

    results: list[dict] = []
    for c in raw_list:
        if not isinstance(c, dict):
            continue
        email = c.get("email") or None
        website = c.get("homePage") or None

        if not email and not website:
            continue

        if website and any(d in website for d in _SKIP_DOMAINS):
            website = None

        name = c.get("name") or c.get("legalName") or ""

        addr_parts = []
        va = c.get("visitorAddress") or c.get("postalAddress") or {}
        if isinstance(va, dict):
            street = va.get("street") or ""
            zip_code = va.get("zip") or va.get("zipCode") or ""
            city_name = va.get("city") or va.get("place") or ""
            if street:
                addr_parts.append(street)
            if zip_code and city_name:
                addr_parts.append(f"{zip_code} {city_name}")
            elif city_name:
                addr_parts.append(city_name)
        address = ", ".join(filter(None, addr_parts)) or None

        phone = c.get("mobile") or c.get("phone") or c.get("phone2") or None
        if phone:
            phone = str(phone).strip()
            if not phone.startswith("+"):
                phone = "+45" + phone.lstrip("0")

        results.append({
            "name": name.strip(),
            "email": email,
            "website": website,
            "phone": phone,
            "address": address,
        })

    return results


def _get_total_pages(data: dict) -> int:
    store = (
        data.get("pageProps", {})
        .get("hydrationData", {})
        .get("searchStore", {})
    )
    return store.get("companies", {}).get("pages", 1) or 1


async def scrape_category(city: str, category: str) -> list[dict]:
    """
    Scrape proff.dk for a given city and category (Danish search term).

    Args:
        city:     Danish city name (e.g. "København", "Aarhus")
        category: Danish search term (e.g. "restaurant", "frisør")
    """
    from scraper import _get_concurrency, _load_proxies

    concurrency = _get_concurrency()
    semaphore = asyncio.Semaphore(concurrency)
    proxy_count = len(_load_proxies())

    log.info("Scraping DK %s/%s — concurrency=%d, proxies=%d", city, category, concurrency, proxy_count)

    connector = aiohttp.TCPConnector(limit=concurrency + 5, force_close=True)
    jar = aiohttp.CookieJar(unsafe=True)

    results: list[dict] = []
    seen_names: set[str] = set()

    async with aiohttp.ClientSession(connector=connector, cookie_jar=jar) as session:
        build_id = await _get_build_id(session, semaphore)
        if not build_id:
            log.error("Could not determine proff.dk build ID — aborting")
            return []

        log.debug("proff.dk build ID: %s", build_id)

        def _api_url(page: int) -> str:
            import urllib.parse
            q = urllib.parse.quote(category)
            g = urllib.parse.quote(city)
            return f"{BASE_URL}/_next/data/{build_id}/search.json?q={q}&geo={g}&lang=da&page={page}"

        data = await _fetch(session, _api_url(1), semaphore)
        if not data:
            log.warning("No response for DK %s/%s page 1", city, category)
            return []

        listings = _extract_companies(data)
        total_pages = min(_get_total_pages(data), MAX_PAGES)

        log.info("DK %s/%s — page 1/%d → %d listings", city, category, total_pages, len(listings))

        for biz in listings:
            key = biz["name"].lower()
            if key and key not in seen_names:
                seen_names.add(key)
                results.append(biz)

        if total_pages > 1:
            for batch_start in range(2, total_pages + 1, concurrency):
                batch = list(range(batch_start, min(batch_start + concurrency, total_pages + 1)))
                tasks = [_fetch(session, _api_url(p), semaphore) for p in batch]
                pages_data = await asyncio.gather(*tasks)

                for page_num, page_data in zip(batch, pages_data):
                    if not page_data:
                        continue
                    page_listings = _extract_companies(page_data)
                    log.debug("DK %s/%s page %d → %d listings", city, category, page_num, len(page_listings))
                    for biz in page_listings:
                        key = biz["name"].lower()
                        if key and key not in seen_names:
                            seen_names.add(key)
                            results.append(biz)

                await asyncio.sleep(
                    random.uniform(0.5, 1.5) if proxy_count >= 100 else random.uniform(2, 4)
                )

    log.info("Finished DK %s/%s — %d results", city, category, len(results))
    return results
