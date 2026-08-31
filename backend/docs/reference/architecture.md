---
title: Architecture Reference
category: reference
tags: [architecture, structure, tech-stack, laravel, nextjs, shadcn, omnify, brand, middleware]
summary: Describes the project structure, tech stack, Platform authentication, and Brand/Shop middleware for TempoFast.
related: [getting-started, api-overview, product-domain]
---

# Architecture Reference

This document describes the project structure, tech stack, and authentication modes for TempoFast.

## Project Structure

```text
dxs-product/
├── ./                 # Laravel 13 API server
│   ├── app/
│   │   ├── Http/
│   │   │   ├── Controllers/Api/V1/   # API controllers
│   │   │   └── Middleware/
│   │   │       ├── ResolveBrandFromSlug.php   # Resolves {brandSlug} to Brand model
│   │   │       └── ResolveShopFromSlug.php    # Resolves {shopSlug} to Branch model
│   │   ├── Models/
│   │   │   ├── Brand.php             # Brand entity (cache from Platform SSO)
│   │   │   └── ...
│   │   └── Services/
│   ├── config/              # Configuration files
│   ├── database/
│   │   ├── factories/
│   │   │   ├── BrandFactory.php      # Factory for Brand model
│   │   │   └── ...
│   │   ├── migrations/
│   │   └── seeders/
│   ├── packages/
│   │   └── app/Sso/         # Platform JIT provisioning
│   ├── routes/              # API route definitions
│   └── .env                 # Environment configuration
├── frontend/                # Next.js SPA
│   ├── src/
│   │   ├── app/
│   │   │   ├── brands/[brandSlug]/   # Brand-scoped pages
│   │   │   │   ├── products/         # Product management
│   │   │   │   ├── categories/       # Category management
│   │   │   │   ├── materials/        # Material management
│   │   │   │   ├── recipes/          # Recipe management
│   │   │   │   └── menus/            # Menu management
│   │   │   ├── shops/[shopSlug]/     # Shop-scoped pages
│   │   │   │   ├── warehouses/       # Warehouse management
│   │   │   │   ├── stock/            # Stock operations
│   │   │   │   └── disposals/        # Disposal management
│   │   │   ├── login/                # SSO login page
│   │   │   └── login/callback/       # SSO callback handler
│   │   ├── components/ui/   # shadcn/ui components
│   │   └── lib/             # Utilities
│   └── .env.local           # Frontend environment variables
└── docs/                    # Documentation
```

## Tech Stack

| Layer    | Technology                                       |
| -------- | ------------------------------------------------ |
| Backend  | PHP 8.4, Laravel 13, Sanctum 4                   |
| Frontend | Next.js (App Router), Tailwind v4, shadcn/ui     |
| Auth     | dxs/laravel-auth (OAuth2/OIDC BFF)               |
| IDP      | DXS Platform (`platform.test`)                   |
| Database | MySQL                                            |
| Server   | Docker compose (`http://localhost:5400`)         |

## Authentication Modes

Controlled by the `OMNIFY_AUTH_MODE` environment variable:

| Mode         | Description                                          |
| ------------ | ---------------------------------------------------- |
| `console`    | OAuth2 SSO via Platform IDP (production)             |
| `standalone` | Local email/password with Sanctum tokens (development/standalone) |

See [SSO Authentication](../guide/sso-authentication.md) for detailed configuration and flow.
