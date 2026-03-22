import asyncio
import os
import random
import re
import time
import logging
import aiohttp
from bs4 import BeautifulSoup
from random_user_agent.user_agent import UserAgent
from random_user_agent.params import SoftwareName, OperatingSystem
from urllib.parse import quote

log = logging.getLogger("scraper")

software_names = [SoftwareName.CHROME.value]
os_names = [OperatingSystem.WINDOWS.value, OperatingSystem.LINUX.value]
user_agent_rotator = UserAgent(software_names=software_names, operating_systems=os_names, limit=100)

BASE_URL = "https://www.local.ch/en"

MAX_RETRIES = 4
NO_PROXY_COOLDOWN = 180

_BASE_DIR = os.path.dirname(__file__)
_proxies: list[str] = []
_proxies_loaded = False
_blocked_proxies: dict[str, float] = {}
_global_cooldown_until = 0.0


# ── Proxy Management ──────────────────────────────────────────

def _load_proxies() -> list[str]:
    global _proxies, _proxies_loaded
    if _proxies_loaded:
        return _proxies

    proxy_file = os.path.join(_BASE_DIR, "proxies.txt")
    if os.path.exists(proxy_file):
        with open(proxy_file) as f:
            _proxies = [
                line.strip() for line in f
                if line.strip() and not line.startswith("#")
            ]
        if _proxies:
            log.info("Loaded %d proxies from proxies.txt", len(_proxies))
    _proxies_loaded = True
    return _proxies


def reload_proxies():
    global _proxies_loaded, _blocked_proxies
    _proxies_loaded = False
    _blocked_proxies.clear()
    _load_proxies()


def _get_proxy() -> str | None:
    proxies = _load_proxies()
    if not proxies:
        return None
    now = time.monotonic()
    available = [p for p in proxies if _blocked_proxies.get(p, 0) < now]
    if not available:
        _blocked_proxies.clear()
        available = proxies
    return random.choice(available)


def _block_proxy(proxy: str, seconds: int = 120):
    if proxy:
        _blocked_proxies[proxy] = time.monotonic() + seconds
        log.debug("Blocked proxy %s for %ds (%d/%d available)",
                  proxy.split("@")[-1] if "@" in proxy else proxy,
                  seconds, len(_load_proxies()) - len(_blocked_proxies), len(_load_proxies()))


def _get_concurrency() -> int:
    count = len(_load_proxies())
    if count >= 500:
        return 20
    if count >= 100:
        return 10
    if count >= 50:
        return 5
    if count >= 10:
        return 3
    return 1


def _get_delay() -> tuple[float, float]:
    count = len(_load_proxies())
    if count >= 500:
        return (0.3, 1.0)
    if count >= 100:
        return (0.5, 1.5)
    if count >= 50:
        return (1.0, 2.5)
    if count >= 10:
        return (1.5, 3.5)
    return (3.0, 6.0)


# ── HTTP ──────────────────────────────────────────────────────

def _random_headers(referer: str | None = None) -> dict:
    headers = {
        "User-Agent": user_agent_rotator.get_random_user_agent(),
        "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8",
        "Accept-Language": "en-CH,en;q=0.9,de-CH;q=0.8,de;q=0.7",
        "Accept-Encoding": "gzip, deflate, br",
        "DNT": "1",
        "Connection": "keep-alive",
        "Upgrade-Insecure-Requests": "1",
        "Sec-Fetch-Dest": "document",
        "Sec-Fetch-Mode": "navigate",
        "Sec-Fetch-Site": "same-origin" if referer else "none",
        "Sec-Fetch-User": "?1",
    }
    if referer:
        headers["Referer"] = referer
    return headers


async def _wait_for_cooldown():
    global _global_cooldown_until
    now = time.monotonic()
    if now < _global_cooldown_until:
        wait = _global_cooldown_until - now
        log.info("Global cooldown — waiting %.0fs", wait)
        await asyncio.sleep(wait)


async def _fetch(
    session: aiohttp.ClientSession,
    url: str,
    semaphore: asyncio.Semaphore,
    referer: str | None = None,
) -> str | None:
    global _global_cooldown_until
    min_delay, max_delay = _get_delay()

    for attempt in range(MAX_RETRIES):
        async with semaphore:
            await _wait_for_cooldown()
            await asyncio.sleep(random.uniform(min_delay, max_delay) + (attempt * 1.5))

            proxy = _get_proxy()
            try:
                proxy_label = proxy.split("//")[-1] if proxy else "direct"
                async with session.get(
                    url,
                    headers=_random_headers(referer),
                    proxy=proxy,
                    timeout=aiohttp.ClientTimeout(total=25),
                    allow_redirects=True,
                ) as resp:
                    if resp.status == 200:
                        log.debug("OK 200 via %s: %s", proxy_label, url)
                        return await resp.text()
                    log.warning("HTTP %d via %s (attempt %d/%d): %s",
                                resp.status, proxy_label, attempt + 1, MAX_RETRIES, url)
                    if resp.status in (429, 403):
                        _block_proxy(proxy, 120)
                        if not _load_proxies():
                            _global_cooldown_until = time.monotonic() + NO_PROXY_COOLDOWN
                            await asyncio.sleep(NO_PROXY_COOLDOWN)
                        else:
                            await asyncio.sleep(random.uniform(2, 5))
                        continue
                    if resp.status >= 500:
                        await asyncio.sleep(5 * (attempt + 1))
                        continue
                    return None
            except aiohttp.ClientHttpProxyError as e:
                proxy_label = proxy.split("//")[-1] if proxy else "direct"
                if e.status == 407:
                    log.debug("407 via %s — rotating proxy", proxy_label)
                    await asyncio.sleep(1)
                    continue
                log.warning("Proxy error via %s (attempt %d/%d): %s",
                            proxy_label, attempt + 1, MAX_RETRIES, e)
                _block_proxy(proxy, 60)
            except (aiohttp.ClientError, asyncio.TimeoutError) as e:
                proxy_label = proxy.split("//")[-1] if proxy else "direct"
                log.warning("Connection error via %s (attempt %d/%d): %s — %s",
                            proxy_label, attempt + 1, MAX_RETRIES, type(e).__name__, e)
                _block_proxy(proxy, 60)
                await asyncio.sleep(3 * (attempt + 1))
            except Exception as e:
                log.error("Unexpected error: %s — %s", type(e).__name__, e)
                return None
    log.warning("Failed after %d retries: %s", MAX_RETRIES, url)
    return None


# ── Parsing ───────────────────────────────────────────────────

def _extract_listing_links(html: str) -> list[str]:
    detail_links = re.findall(r'href="(/en/d/[^"]+)"', html)
    seen = set()
    result = []
    for href in detail_links:
        full = f"https://www.local.ch{href}"
        if full not in seen:
            seen.add(full)
            result.append(full)
    return result


def _extract_business(html: str) -> dict:
    soup = BeautifulSoup(html, "html.parser")
    data = {}

    h1 = soup.find("h1", attrs={"data-cy": "header-title"})
    data["name"] = h1.get_text(strip=True) if h1 else None

    tel_link = soup.find("a", href=lambda h: h and h.startswith("tel:"))
    data["phone"] = tel_link["href"].replace("tel:", "") if tel_link else None

    mail_link = soup.find("a", href=lambda h: h and h.startswith("mailto:"))
    data["email"] = mail_link["href"].replace("mailto:", "") if mail_link else None

    addr_parts = re.findall(r'"(?:streetAddress)"\s*:\s*"([^"]+)"', html)
    locality = re.findall(r'"(?:addressLocality)"\s*:\s*"([^"]+)"', html)
    postal = re.findall(r'"(?:postalCode)"\s*:\s*"([^"]+)"', html)
    if addr_parts:
        address = addr_parts[0]
        if postal:
            address += f", {postal[0]}"
        if locality:
            address += f" {locality[0]}"
        data["address"] = address
    else:
        map_preview = soup.find(attrs={"data-cy": "detail-map-preview"})
        if map_preview:
            divs = map_preview.find_all("div")
            for div in divs:
                text = div.get_text(strip=True)
                if text and "Address:" not in text and len(text) > 5 and any(c.isdigit() for c in text):
                    data["address"] = text
                    break

    social_domains = ("facebook.com", "instagram.com", "twitter.com", "linkedin.com",
                      "youtube.com", "tiktok.com", "localsearch.ch", "local.ch")
    for a in soup.find_all("a", href=True):
        href = a.get("href", "")
        if href.startswith("http") and not any(d in href for d in social_domains):
            data["website"] = href
            break

    return data


# ── Main Scraper ──────────────────────────────────────────────

async def scrape_category(city: str, category: str) -> list[dict]:
    results = []
    seen_links = set()
    concurrency = _get_concurrency()
    semaphore = asyncio.Semaphore(concurrency)

    proxy_count = len(_load_proxies())
    log.info("Scraping %s/%s — concurrency=%d, proxies=%d", city, category, concurrency, proxy_count)

    connector = aiohttp.TCPConnector(limit=concurrency + 5, force_close=True)
    jar = aiohttp.CookieJar(unsafe=True)

    async with aiohttp.ClientSession(connector=connector, cookie_jar=jar) as session:
        page = 1
        consecutive_fails = 0

        while True:
            encoded_cat = quote(category, safe="")
            encoded_city = quote(city, safe="")
            url = f"{BASE_URL}/q/{encoded_city}/{encoded_cat}?page={page}"

            html = await _fetch(session, url, semaphore)
            if not html:
                consecutive_fails += 1
                if consecutive_fails >= 2:
                    log.warning("Stopping %s/%s — too many consecutive page failures", city, category)
                    break
                page += 1
                continue

            consecutive_fails = 0
            links = _extract_listing_links(html)
            new_links = [l for l in links if l not in seen_links]
            if not new_links:
                break

            seen_links.update(new_links)

            if concurrency > 1:
                tasks = [_fetch(session, link, semaphore, referer=url) for link in new_links]
                pages_html = await asyncio.gather(*tasks)
                for page_html in pages_html:
                    if page_html:
                        biz = _extract_business(page_html)
                        if biz.get("name"):
                            results.append(biz)
            else:
                for link in new_links:
                    page_html = await _fetch(session, link, semaphore, referer=url)
                    if page_html:
                        biz = _extract_business(page_html)
                        if biz.get("name"):
                            results.append(biz)

            page += 1
            await asyncio.sleep(random.uniform(1, 3) if proxy_count >= 50 else random.uniform(5, 12))

    log.info("Finished %s/%s — %d results", city, category, len(results))
    return results
