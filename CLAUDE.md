# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**serenisoft-atum-enhancer** is a WooCommerce plugin that extends ATUM Inventory Management with:
- Automatic purchase order suggestions based on stock levels, lead times, and seasonal sales patterns
- Smart ordering strategy (limit orders to 2-4 times per year per supplier)
- Supplier CSV import functionality
- Scheduled tasks with email notifications

## ATUM Reference Code

The ATUM plugin source code is available at:
```
C:\dev\references\atum-stock-manager-for-woocommerce
```

Key ATUM classes to integrate with:
- `\Atum\PurchaseOrders\PurchaseOrders` - Purchase order post type and management
- `\Atum\PurchaseOrders\Models\PurchaseOrder` - PO model with supplier assignment
- `\Atum\Suppliers\Supplier` - Supplier data model (lead_time, code, contact info)
- `\Atum\Settings\Settings` - Settings page integration

## Build Commands

```bash
npm install          # Install frontend dependencies
npm run build        # Build all assets (gulp)
npm run watch        # Watch for changes
npm run lint:scripts # Lint JavaScript/TypeScript

composer install     # Install PHP dependencies
./vendor/bin/phpcs   # Run PHP CodeSniffer

# Translations (use WP CLI, not custom scripts)
wp i18n make-pot . languages/serenisoft-atum-enhancer.pot  # Generate POT file
wp i18n make-json languages/                                # Generate JSON for JS translations
```

## Architecture

Namespace: `SereniSoft\AtumEnhancer\`

```
serenisoft-atum-enhancer/
├── serenisoft-atum-enhancer.php    # Main plugin file
├── classes/                         # PSR-4 autoloaded classes
│   ├── Bootstrap.php               # Plugin initialization (singleton)
│   ├── PurchaseOrderSuggestions/   # PO suggestion algorithm
│   ├── SupplierImport/             # CSV import functionality
│   ├── Settings/                   # ATUM settings integration
│   └── Components/                 # Shared components
├── assets/                         # JS, CSS, images
├── languages/                      # Translation files (.pot, .po, .mo)
├── views/                          # Admin view templates
└── tasks/                          # Task tracking (todo.md)
```

## Coding Standards

- Follow WordPress Coding Standards
- Use PSR-4 autoloading via Composer
- Prefix hooks and globals with `sae_` (SereniSoft Atum Enhancer)
- Settings appear under: ATUM Inventory → Settings (Enhancer)

### Internationalization (i18n)
- Plugin supports multiple languages
- All user-facing text must use translation functions: `__()`, `_e()`, `esc_html__()`
- Text domain: `serenisoft-atum-enhancer`
- Use WP CLI for generating translation files - do NOT create custom scripts
- POT file location: `languages/serenisoft-atum-enhancer.pot`

## Development Workflow

1. Write plan to `tasks/todo.md` with checkboxes
2. Get plan approval before starting work
3. Work on todo items, marking complete as you go
4. Keep changes simple and minimal - impact as little code as possible
5. Add review section to `tasks/todo.md` when complete

## Rules

- NO lazy fixes - find and fix root causes
- NO temporary workarounds
- Keep changes as simple as humanly possible
- Only modify code directly relevant to the task
- Goal: zero new bugs introduced
