import logging
from contextlib import asynccontextmanager
from fastapi import FastAPI
from fastapi.middleware.cors import CORSMiddleware

from db import init_db
from routers import scraper, auth, dashboard, admin

logging.basicConfig(level=logging.INFO)
logging.getLogger("scraper").setLevel(logging.DEBUG)
logging.getLogger("scraper_de").setLevel(logging.DEBUG)


@asynccontextmanager
async def lifespan(app: FastAPI):
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

app.include_router(scraper.router)
app.include_router(auth.router)
app.include_router(dashboard.router)
app.include_router(admin.router)


@app.get("/")
async def root():
    return {"service": "Ch-Scraper API", "version": "2.0.0", "docs": "/docs"}
