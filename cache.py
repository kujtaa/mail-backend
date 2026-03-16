import time

CACHE_TIMEOUT = 300  # 5 minutes

_cache: dict[str, dict] = {
    "total_scraped": {"value": None, "timestamp": 0},
    "total_emails": {"value": None, "timestamp": 0},
    "category_counts": {"value": None, "timestamp": 0},
}


def get_cached(key: str):
    entry = _cache.get(key)
    if entry and entry["value"] is not None:
        if time.time() - entry["timestamp"] < CACHE_TIMEOUT:
            return entry["value"]
    return None


def set_cached(key: str, value):
    _cache[key] = {"value": value, "timestamp": time.time()}


def invalidate_cache():
    for key in _cache:
        _cache[key] = {"value": None, "timestamp": 0}
