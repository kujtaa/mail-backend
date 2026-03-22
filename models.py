from datetime import datetime
from sqlalchemy import (
    Column, Integer, String, Boolean, Float, Text, DateTime,
    ForeignKey, UniqueConstraint, Index,
)
from sqlalchemy.orm import relationship
from db import Base


# ── Scraper Tables ──────────────────────────────────────────────

class City(Base):
    __tablename__ = "cities"
    id = Column(Integer, primary_key=True, index=True)
    name = Column(String(255), unique=True, nullable=False, index=True)
    businesses = relationship("Business", back_populates="city")


class Category(Base):
    __tablename__ = "categories"
    id = Column(Integer, primary_key=True, index=True)
    name = Column(String(255), unique=True, nullable=False, index=True)
    businesses = relationship("Business", back_populates="category")
    email_batches = relationship("EmailBatch", back_populates="category")


class Business(Base):
    __tablename__ = "businesses"
    id = Column(Integer, primary_key=True, index=True)
    name = Column(String(191), nullable=False, index=True)
    phone = Column(String(100))
    email = Column(String(255))
    address = Column(Text)
    website = Column(String(500))
    city_id = Column(Integer, ForeignKey("cities.id"), nullable=False)
    category_id = Column(Integer, ForeignKey("categories.id"), nullable=False)
    source = Column(String(50), nullable=False, default="local.ch", server_default="local.ch")

    city = relationship("City", back_populates="businesses")
    category = relationship("Category", back_populates="businesses")
    batch_emails = relationship("BatchEmail", back_populates="business")

    __table_args__ = (
        Index("ix_biz_city_cat_name", "city_id", "category_id", "name"),
        Index("ix_biz_source", "source"),
    )


# ── SaaS / Dashboard Tables ────────────────────────────────────

class Company(Base):
    __tablename__ = "companies"
    id = Column(Integer, primary_key=True, index=True)
    name = Column(String(255), nullable=False)
    email = Column(String(255), unique=True, nullable=False, index=True)
    hashed_password = Column(String(255), nullable=False)
    credit_balance = Column(Float, default=0.0)
    is_admin = Column(Boolean, default=False)
    is_approved = Column(Boolean, nullable=False, default=False, server_default="0")
    plan = Column(String(20), nullable=False, default="free", server_default="free")
    plan_expires_at = Column(DateTime, nullable=True)
    daily_send_limit = Column(Integer, nullable=False, default=0, server_default="0")
    daily_sends_used = Column(Integer, nullable=False, default=0, server_default="0")
    daily_sends_reset_at = Column(DateTime, nullable=True)
    smtp_host = Column(String(255), nullable=True)
    smtp_port = Column(Integer, nullable=True)
    smtp_user = Column(String(255), nullable=True)
    smtp_pass = Column(String(500), nullable=True)
    smtp_from_email = Column(String(255), nullable=True)
    smtp_from_name = Column(String(255), nullable=True)
    smtp_enabled = Column(Boolean, nullable=False, default=False, server_default="0")
    allowed_sources = Column(Text, nullable=True)
    created_at = Column(DateTime, default=datetime.utcnow)
    updated_at = Column(DateTime, default=datetime.utcnow, onupdate=datetime.utcnow)

    def get_allowed_sources(self) -> list[str]:
        if not self.allowed_sources:
            return []
        return [s.strip() for s in self.allowed_sources.split(",") if s.strip()]

    def set_allowed_sources(self, sources: list[str]):
        self.allowed_sources = ",".join(sources) if sources else None

    email_batches = relationship("EmailBatch", back_populates="company")
    sent_emails = relationship("SentEmail", back_populates="company")
    credit_transactions = relationship("CreditTransaction", back_populates="company")


class EmailBatch(Base):
    __tablename__ = "email_batches"
    id = Column(Integer, primary_key=True, index=True)
    company_id = Column(Integer, ForeignKey("companies.id"), nullable=False)
    category_id = Column(Integer, ForeignKey("categories.id"), nullable=True)
    city_id = Column(Integer, ForeignKey("cities.id"), nullable=True)
    label = Column(String(500), nullable=True)
    batch_size = Column(Integer, nullable=False)
    price_paid = Column(Float, nullable=False)
    purchased_at = Column(DateTime, default=datetime.utcnow)

    company = relationship("Company", back_populates="email_batches")
    category = relationship("Category", back_populates="email_batches")
    city = relationship("City")
    batch_emails = relationship("BatchEmail", back_populates="batch")


class BatchEmail(Base):
    __tablename__ = "batch_emails"
    id = Column(Integer, primary_key=True, index=True)
    batch_id = Column(Integer, ForeignKey("email_batches.id"), nullable=False)
    business_id = Column(Integer, ForeignKey("businesses.id"), nullable=False)

    batch = relationship("EmailBatch", back_populates="batch_emails")
    business = relationship("Business", back_populates="batch_emails")
    sent_emails = relationship("SentEmail", back_populates="batch_email")


class SentEmail(Base):
    __tablename__ = "sent_emails"
    id = Column(Integer, primary_key=True, index=True)
    company_id = Column(Integer, ForeignKey("companies.id"), nullable=False)
    batch_email_id = Column(Integer, ForeignKey("batch_emails.id"), nullable=False)
    subject = Column(String(500))
    body = Column(Text)
    sent_at = Column(DateTime, default=datetime.utcnow)
    status = Column(String(20), default="pending")  # pending | sent | failed

    company = relationship("Company", back_populates="sent_emails")
    batch_email = relationship("BatchEmail", back_populates="sent_emails")


class CreditTransaction(Base):
    __tablename__ = "credit_transactions"
    id = Column(Integer, primary_key=True, index=True)
    company_id = Column(Integer, ForeignKey("companies.id"), nullable=False)
    amount = Column(Float, nullable=False)
    type = Column(String(20), nullable=False)  # topup | purchase
    description = Column(Text)
    created_at = Column(DateTime, default=datetime.utcnow)

    company = relationship("Company", back_populates="credit_transactions")
