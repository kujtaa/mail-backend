from datetime import datetime
from typing import Optional
from pydantic import BaseModel, EmailStr


# ── Auth ────────────────────────────────────────────────────────

class RegisterRequest(BaseModel):
    company_name: str
    email: EmailStr
    password: str

class LoginRequest(BaseModel):
    email: EmailStr
    password: str

class TokenResponse(BaseModel):
    access_token: str
    token_type: str = "bearer"

class CompanyProfile(BaseModel):
    id: int
    name: str
    email: str
    credit_balance: float
    is_admin: bool
    is_approved: bool = False
    plan: str = "free"
    plan_expires_at: Optional[datetime] = None
    daily_send_limit: int = 0
    daily_sends_used: int = 0
    created_at: datetime

    class Config:
        from_attributes = True


# ── Scraper ─────────────────────────────────────────────────────

class ScrapeRequest(BaseModel):
    city: str
    category: str
    source: str = "local.ch"

class BusinessOut(BaseModel):
    id: int
    name: str
    phone: Optional[str] = None
    email: Optional[str] = None
    address: Optional[str] = None
    website: Optional[str] = None
    city: Optional[str] = None
    category: Optional[str] = None

    class Config:
        from_attributes = True


# ── Dashboard ───────────────────────────────────────────────────

class DashboardStats(BaseModel):
    total_emails_available: int
    total_businesses: int
    total_with_website: int
    total_without_website: int
    total_categories: int
    total_cities: int
    emails_purchased: int
    emails_sent: int
    emails_failed: int
    credit_balance: float
    batches_count: int
    smtp_configured: bool = False
    plan: str = "free"
    daily_send_limit: int = 0
    daily_sends_remaining: int = 0

class CategoryCount(BaseModel):
    category_id: int
    category_name: str
    available_count: int

class EmailPreview(BaseModel):
    id: int
    business_name: str
    email: str
    city: str
    category: str

class PurchaseBatchRequest(BaseModel):
    category: Optional[str] = None
    city: Optional[str] = None
    batch_size: int

class PurchaseMultiBatchRequest(BaseModel):
    categories: list[str]
    city: Optional[str] = None

class BatchOut(BaseModel):
    id: int
    label: Optional[str] = None
    category_name: Optional[str] = None
    city_name: Optional[str] = None
    batch_size: int
    price_paid: float
    purchased_at: Optional[datetime] = None

class BatchEmailOut(BaseModel):
    id: int
    business_name: str
    email: str
    city: str
    category: str

class SendEmailRequest(BaseModel):
    batch_email_ids: list[int]
    subject: str
    body: str

class SendManualRequest(BaseModel):
    emails: list[str]
    subject: str
    body: str

class SentEmailOut(BaseModel):
    id: int
    recipient_email: str
    subject: str
    sent_at: Optional[datetime] = None
    status: str


# ── SMTP Settings ──────────────────────────────────────────────

class SmtpSettingsIn(BaseModel):
    smtp_host: str
    smtp_port: int = 587
    smtp_user: str
    smtp_pass: str
    smtp_from_email: str
    smtp_from_name: str = ""
    smtp_enabled: bool = True
    email_signature: Optional[str] = None

class SmtpSettingsOut(BaseModel):
    smtp_host: Optional[str] = None
    smtp_port: Optional[int] = None
    smtp_user: Optional[str] = None
    smtp_from_email: Optional[str] = None
    smtp_from_name: Optional[str] = None
    smtp_enabled: bool = False
    has_password: bool = False
    email_signature: Optional[str] = None

    class Config:
        from_attributes = True

class SmtpTestRequest(BaseModel):
    to_email: str


# ── Admin ───────────────────────────────────────────────────────

class AddCreditsRequest(BaseModel):
    company_id: int
    amount: float
    description: str = ""

class CompanyAdmin(BaseModel):
    id: int
    name: str
    email: str
    credit_balance: float
    is_admin: bool
    is_approved: bool = False
    plan: str = "free"
    plan_expires_at: Optional[datetime] = None
    daily_send_limit: int = 0
    allowed_sources: list[str] = []
    batches_count: int
    total_purchased_emails: int
    created_at: datetime

class SetSourcesRequest(BaseModel):
    company_id: int
    sources: list[str]

class TransactionOut(BaseModel):
    id: int
    company_id: int
    company_name: str
    amount: float
    type: str
    description: Optional[str] = None
    created_at: datetime
