import asyncio
import random
import uuid
import logging
from datetime import datetime

log = logging.getLogger("scrape_jobs")

jobs: dict[str, dict] = {}
_queue: asyncio.Queue | None = None
_worker_task: asyncio.Task | None = None


def _get_queue() -> asyncio.Queue:
    global _queue
    if _queue is None:
        _queue = asyncio.Queue()
    return _queue


def create_job(city: str, category: str, source: str = "local.ch") -> str:
    job_id = str(uuid.uuid4())[:8]
    jobs[job_id] = {
        "id": job_id,
        "city": city,
        "category": category,
        "source": source,
        "status": "queued",
        "count": 0,
        "saved": 0,
        "error": None,
        "started_at": datetime.utcnow().isoformat(),
        "finished_at": None,
    }
    return job_id


def mark_running(job_id: str):
    if job_id in jobs:
        jobs[job_id]["status"] = "running"


def finish_job(job_id: str, count: int, saved: int, error: str | None = None):
    if job_id in jobs:
        jobs[job_id]["status"] = "failed" if error else "completed"
        jobs[job_id]["count"] = count
        jobs[job_id]["saved"] = saved
        jobs[job_id]["error"] = error
        jobs[job_id]["finished_at"] = datetime.utcnow().isoformat()


def get_job(job_id: str) -> dict | None:
    return jobs.get(job_id)


def get_all_jobs() -> list[dict]:
    return sorted(jobs.values(), key=lambda j: j["started_at"], reverse=True)


async def enqueue_job(
    job_id: str,
    city: str,
    category: str,
    source: str = "local.ch",
    db_category: str | None = None,
):
    q = _get_queue()
    await q.put((job_id, city, category, source, db_category or category))
    _ensure_worker()


def _ensure_worker():
    global _worker_task
    if _worker_task is None or _worker_task.done():
        _worker_task = asyncio.get_event_loop().create_task(_worker())


async def _worker():
    """Processes scrape jobs one at a time, routing to the correct scraper."""
    from scraper import scrape_category as scrape_ch
    from scraper_de import scrape_category as scrape_de
    from crud import save_scraped_data
    from cache import invalidate_cache
    from db import async_session

    q = _get_queue()
    while True:
        try:
            job_id, city, category, source, db_category = await asyncio.wait_for(q.get(), timeout=60)
        except asyncio.TimeoutError:
            log.info("Job queue idle for 60s, worker exiting")
            break

        mark_running(job_id)
        log.info("Starting scrape: %s — %s / %s [%s]", job_id, city, category, source)

        try:
            if source == "gelbeseiten.de":
                scraped = await scrape_de(city, category)
            else:
                scraped = await scrape_ch(city, category)

            async with async_session() as db:
                if scraped:
                    saved = await save_scraped_data(db, city, db_category, scraped, source=source)
                    invalidate_cache()
                    finish_job(job_id, count=len(scraped), saved=saved)
                    log.info("Completed %s: %d found, %d saved", job_id, len(scraped), saved)
                else:
                    finish_job(job_id, count=0, saved=0)
                    log.info("Completed %s: 0 results", job_id)
        except Exception as e:
            log.error("Job %s failed: %s", job_id, e)
            finish_job(job_id, count=0, saved=0, error=str(e))

        q.task_done()

        if not q.empty():
            from scraper import _load_proxies
            proxy_count = len(_load_proxies())
            if proxy_count >= 500:
                pause = random.uniform(1, 3)
            elif proxy_count >= 50:
                pause = random.uniform(3, 8)
            elif proxy_count >= 10:
                pause = random.uniform(10, 20)
            else:
                pause = random.uniform(30, 60)
            log.info("Pausing %.0fs before next job (proxies=%d)", pause, proxy_count)
            await asyncio.sleep(pause)
