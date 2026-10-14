<div align="center">

# Khums Finance

**A Persian-first personal finance project built with Laravel and Livewire.**

[![PHP](https://img.shields.io/badge/PHP-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![Laravel](https://img.shields.io/badge/Laravel-FF2D20?logo=laravel&logoColor=white)](https://laravel.com/)
[![Livewire](https://img.shields.io/badge/Livewire-4E56A6?logo=livewire&logoColor=white)](https://livewire.laravel.com/)
![Status](https://img.shields.io/badge/status-in%20development-orange)
![Phase](https://img.shields.io/badge/current%20phase-5b-blue)

[Overview](#overview) · [Current Status](#current-status) · [Getting Started](#getting-started) · [Documentation](#documentation)

</div>

---

## Overview

**Khums Finance** is a personal project exploring a simple, privacy-conscious way to manage financial records and, in later phases, support Khums-related tracking and calculations. The interface is intended for Persian-speaking users.

The project is being developed incrementally. **It is not yet a complete finance or Khums calculation application.** The phase-five financial workflow is ready for review with synthetic data; religious calculations and real-data acceptance remain separate.

## Current Status

**Phase 5b — Limited CSV import ready for synthetic-data review**

The current implementation includes:

- Initial Laravel / Livewire application foundation and base pages.
- Single-owner authentication with sign-in and sign-out.
- Owner account creation via a CLI command, without public registration.
- Local owner password reset via a CLI command, without a public password recovery flow.
- Opening balances, cash and quantity-asset transactions, historical posting with atomic replay, opening amendments, FX obligation settlement and cash-flow review.
- A limited, reviewed CSV batch import for supported cash operations.
- Automated checks for the implemented financial workflow.

**Last reported verification:** 95 tests, 556 assertions; formatting, Blade compilation and frontend build passed. Visual and keyboard-accessibility review remains pending. Phase 6 has not started.

> **Development notice:** Do not enter real financial information until the review and separate acceptance documented in [PROGRESS](docs/PROGRESS.md). Khums and Zakat engines are not implemented.

## Technical Approach

- **Backend:** PHP, Laravel
- **UI:** Livewire, Persian-first interface
- **Development database:** SQLite (local setup)
- **Access model:** Single owner; no public self-registration
- **Workflow:** Phased delivery with documented architecture, roadmap and progress

For architectural decisions and boundaries, see [Architecture](docs/ARCHITECTURE.md).

## Getting Started

For prerequisites, environment configuration, database setup and the complete installation instructions, follow **[docs/SETUP.md](docs/SETUP.md)**.

Once the environment has been prepared, the repository documents these project commands:

```bash
composer check:environment
composer install
composer test
composer format
npm ci
npm run build
```

> These commands are not a replacement for the setup guide. Follow its prescribed order on a fresh installation.

### Owner Account and Local Access

The application is designed for a single owner. After completing the setup guide, the owner account can be created with:

```bash
php artisan owner:create
```

For local password recovery:

```bash
php artisan owner:reset-password
```

To run the local development server:

```bash
php artisan serve --host=127.0.0.1
```

By default, the app will be available at `http://127.0.0.1:8000`.

## Testing and Quality

The implemented workflow has automated tests and formatting/build checks. Run the project-defined commands after installing dependencies:

```bash
composer test
composer format
npm run build
```

See [Progress](docs/PROGRESS.md) for dated verification results and outstanding reviews. Test totals describe a previous reported run, not a claim that the current branch always passes.

## Privacy and Security

- Do not commit `.env` files, SQLite databases, logs, sessions, backups, exports or real financial records.
- The web server must serve only Laravel's `public/` directory.
- Local development is intended to bind to the loopback interface (`127.0.0.1`).
- There is no public registration or password-reset flow in the current single-owner model.

This repository is an application under development and is **not** financial or religious advice.

## Documentation

| Document | Purpose |
| --- | --- |
| [Setup guide](docs/SETUP.md) | Installation and local development |
| [Architecture](docs/ARCHITECTURE.md) | Components, boundaries and design decisions |
| [Roadmap](docs/ROADMAP.md) | Planned phases and priorities |
| [Progress](docs/PROGRESS.md) | Implemented work, verification and pending checks |
| [Agent instructions](AGENTS.md) | Repository-specific development instructions |

## Project Maintainer

Developed by **[Amir Abbas Afshar](https://github.com/AFEWSH)**.

[GitHub Profile](https://github.com/AFEWSH) · [LinkedIn](https://www.linkedin.com/in/amir-abbas-afshar-5b3b72217/)

---

<sub>Project status and scope should be checked against the latest roadmap and progress documentation.</sub>
