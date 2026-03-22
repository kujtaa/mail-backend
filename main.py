import logging
import json
import time
from contextlib import asynccontextmanager
from fastapi import FastAPI, Request
from fastapi.middleware.cors import CORSMiddleware
from starlette.middleware.base import BaseHTTPMiddleware

from db import init_db
from routers import scraper, auth, dashboard, admin

logging.basicConfig(level=logging.INFO)
logging.getLogger("scraper").setLevel(logging.DEBUG)
logging.getLogger("scraper_de").setLevel(logging.DEBUG)

DEBUG_LOG = "/tmp/debug-b36f61.log"

# #region agent log
class CORSDebugMiddleware(BaseHTTPMiddleware):
    async def dispatch(self, request: Request, call_next):
        origin = request.headers.get("origin", "NONE")
        method = request.method
        path = request.url.path
        entry = {"sessionId": "b36f61", "hypothesisId": "A,D", "location": "main.py:middleware", "message": "incoming_request", "data": {"method": method, "path": path, "origin": origin}, "timestamp": int(time.time() * 1000)}
        with open(DEBUG_LOG, "a") as f:
            f.write(json.dumps(entry) + "\n")
        response = await call_next(request)
        cors_header = response.headers.get("access-control-allow-origin", "MISSING")
        entry2 = {"sessionId": "b36f61", "hypothesisId": "A,D", "location": "main.py:middleware_response", "message": "response_cors", "data": {"method": method, "path": path, "origin": origin, "acao_header": cors_header, "status": response.status_code}, "timestamp": int(time.time() * 1000)}
        with open(DEBUG_LOG, "a") as f:
            f.write(json.dumps(entry2) + "\n")
        return response
# #endregion


@asynccontextmanager
async def lifespan(app: FastAPI):
    # #region agent log
    entry = {"sessionId": "b36f61", "hypothesisId": "A", "location": "main.py:lifespan", "message": "uvicorn_started_with_cors_fix", "data": {"origins": ["http://localhost:5173", "http://localhost:3000", "https://mail-hub.pro", "https://www.mail-hub.pro"]}, "timestamp": int(time.time() * 1000)}
    with open(DEBUG_LOG, "a") as f:
        f.write(json.dumps(entry) + "\n")
    # #endregion
    await init_db()
    yield


app = FastAPI(
    title="Ch-Scraper API",
    description="Swiss business scraper & SaaS email lead platform",
    version="2.0.0",
    lifespan=lifespan,
)

app.add_middleware(
    CORSMiddleware,
    allow_origins=[
        "http://localhost:5173",
        "http://localhost:3000",
        "https://mail-hub.pro",
        "https://www.mail-hub.pro",
    ],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

app.add_middleware(CORSDebugMiddleware)

app.include_router(scraper.router)
app.include_router(auth.router)
app.include_router(dashboard.router)
app.include_router(admin.router)


@app.get("/")
async def root():
    return {"service": "Ch-Scraper API", "version": "2.0.0", "docs": "/docs"}
