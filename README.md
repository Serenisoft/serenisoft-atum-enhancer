# SereniSoft ATUM Enhancer

Extends ATUM Inventory Management for WooCommerce with intelligent purchase order suggestions based on stock levels, lead times, seasonal patterns, and supplier closed periods.

## Features

### Automatic PO Suggestions
- **Daily Automatic Generation**: Schedule PO suggestions to run automatically at a specific time
- Analyzes stock levels and sales history
- Calculates optimal reorder points using industry-standard formulas
- Creates draft Purchase Orders per supplier
- Email notifications when suggestions are generated
- Servebolt server-side cron compatible

### Smart Inventory Algorithms
- **Safety Stock**: Prevents stockouts using statistical service levels (90%, 95%, 99%)
- **Trend Detection**: Adjusts for growing or declining sales patterns (70% recent, 30% historical)
- **Seasonal Analysis**: Considers monthly sales variations (requires 365 days history)
- **New Product Handling**: Skips seasonal analysis for products with < 1 year of sales data
- **Inbound Stock Awareness**: Accounts for quantities already on order in pending POs
- **MOQ Support**: Respects Minimum Order Quantity per product

### Restock Status Integration
- **SAE-Controlled Restock**: Overrides ATUM's restock_status with SAE algorithm results
- **Update Restock Status Button**: One-click update in Stock Central toolbar
- **Suggested Quantity Column**: Shows calculated reorder qty in Stock Central
- **Consistent Logic**: Same algorithm for both restock status and PO generation

### Supplier Import & Export
- **Supplier Export**: Export all suppliers to CSV
- **Supplier Import**: CSV import for bulk supplier creation
- **Product-Supplier Mapping**: Export/import SKU to Supplier Code mappings
- **Preview before import**: See what will be imported/skipped before committing
- Norwegian and English column format support
- Duplicate detection by code or name
- BOM (Byte Order Mark) handling for Excel compatibility

### Supplier Closed Periods
- **Global Presets**: Define common closure periods (holidays, vacations) in ATUM Settings
- **Supplier Assignment**: Select global presets or add custom periods per supplier
- **DD-MM Format**: Norwegian date format (e.g., "01-07" for July 1st)
- **Year-Crossing Support**: Automatically handles periods like "20-12 to 05-01" (Christmas)
- **Type A - Delivery Adjustment**: Extends lead time if delivery falls during closure
- **Type B - Predictive Ordering**: Orders early to prevent stockouts during supplier closures
- **Buffer Before Closure**: Safety margin for pre-holiday delivery delays (default 14 days)
- **Buffer After Closure**: Factory ramp-up time after reopening (default 14 days)
- Example: Christmas 20-12 to 05-01 with 14-day buffers → effective closure 06-12 to 19-01

## Requirements

- WordPress 5.9+
- WooCommerce 5.0+
- ATUM Inventory Management for WooCommerce
- PHP 7.4+

## Installation

1. Upload the plugin folder to `/wp-content/plugins/`
2. Run `composer install` in the plugin directory
3. Activate the plugin through WordPress admin
4. Configure settings in **ATUM Inventory > Settings > Enhancer**

## Configuration

### General Settings
- **Enable Automatic Suggestions**: Toggle automatic daily generation
- **Scheduled Run Time**: Choose time of day for automatic generation (00:00-22:00, server time)

### Purchase Order Algorithm
- **Default Orders Per Year**: How often to order from each supplier (1-12)
- **Minimum Days Between Orders**: Prevents too frequent orders
- **Service Level**: Target in-stock percentage (affects safety stock)
- **Include Seasonal Analysis**: Adjust for monthly patterns

### Per-Supplier Settings
Each supplier can override the global default:
- **Orders Per Year**: Found in "Enhancer Settings" meta box on Supplier edit screen

### Supplier Import
Upload CSV files with semicolon-separated values:
```
Leverandornummer;Navn;Organisasjonsnummer;Telefonnummer;E-postadresse;Postadresse;Postnr.;Sted;Land
```

## Algorithm Parameters Summary

All parameters that affect the ordering calculation:

### Global Settings (ATUM Settings → Enhancer)

| Parameter | Default | Description |
|-----------|---------|-------------|
| Orders Per Year | 4 | How often to order from each supplier (determines days of stock target) |
| Minimum Days Between Orders | 30 | Prevents too frequent orders to the same supplier |
| Service Level | 95% | Target in-stock percentage (90%, 95%, 99%) - affects safety stock |
| Include Seasonal Analysis | Yes | Adjusts for monthly sales patterns (only for products with 365+ days history) |
| Enable Predictive Ordering | Yes | Two-pass system that orders proactively before stockouts |
| Safety Margin (%) | 15% | Include products within this % above reorder point (Pass 2) |
| Time-Based Prediction | Yes | Include products reaching ROP within predictive window (2× base lead time + closed days) |

### Notification Settings (ATUM Settings → Enhancer)

| Parameter | Default | Description |
|-----------|---------|-------------|
| Notification Email (To) | WP Admin Email | Recipient for PO suggestion notifications |
| Notification Email (CC) | - | Optional CC recipient |
| From Name | Site Name | Sender name on notification emails |
| From Email | WP Default | Sender email address |

### Closed Periods Settings

| Parameter | Default | Description |
|-----------|---------|-------------|
| Global Closed Period Presets | - | Define common closure periods (holidays, vacations) |
| Buffer Before Closure | 14 days | Safety margin before official closure (pre-holiday delays) |
| Buffer After Closure | 14 days | Factory ramp-up time after reopening |

### Per-Supplier Settings (Supplier Edit Screen)

| Parameter | Source | Description |
|-----------|--------|-------------|
| Lead Time | ATUM Supplier | Days from order to delivery (critical for all calculations) |
| Orders Per Year Override | Enhancer Settings | Override global orders/year for this supplier |
| Closed Periods | Enhancer Settings | Select global presets or add custom periods |

### Derived Values (Calculated)

| Value | Formula | Description |
|-------|---------|-------------|
| Days of Stock Target | 365 ÷ Orders Per Year | How many days of stock each order should cover |
| Reorder Point (ROP) | (Avg Daily Sales × Lead Time) + Safety Stock | When to trigger reorder |
| Safety Stock | Z-score × Std Dev × √Lead Time | Buffer against demand variability |
| Optimal Inventory | (Avg Daily Sales × Days of Stock) + Safety Stock | Target stock level |
| Effective Stock | Current Stock + Inbound Stock | Available + on-order quantities |

### Adjustment Factors

| Factor | Range | Description |
|--------|-------|-------------|
| Trend Factor | - | 70% recent (30 days), 30% historical |
| Seasonal Factor | 0.5x - 4.0x | Based on future coverage period analysis |
| Global Cap | 0.4x - 10x | Safety net on total adjustments |

## Algorithm Details

### Analysis Process Overview

The plugin uses a two-pass analysis system to determine which products need reordering:

#### **Pass 1 - Basic Reorder Analysis**

**Determines if ordering is needed:**
- Checks if effective stock ≤ reorder point (ROP)
- Formula: ROP = (Average Daily Sales × Lead Time) + Safety Stock
- Orders ONLY when inventory is at or below the reorder point

#### **Pass 2 - Predictive Analysis** (when enabled)

**Additional checks (in addition to Pass 1):**
1. **Safety Margin Check** - Are we within 15% above ROP?
2. **Time-Based Check** - Will we reach ROP within the predictive window?

**Predictive Window Calculation:**
- Base: 2 × base lead time (before closed period adjustment)
- Plus: Any closed days that fall within the window
- Example: Base lead time 49 days → 2 × 49 = 98 days + 40 closed days = 138 days

Pass 2 orders MORE PROACTIVELY - before you actually hit the reorder point.

### Calculation Process

1. **Calculate Average Daily Sales**
   - Adjusts for trend (last 30 days weighted 70%, history 30%)
   - Adjusts for seasonality using future-looking analysis:
     - Calculates when order arrives (today + lead time)
     - Determines coverage period (arrival + days of stock target)
     - Weights historical sales for each month in that future period
     - Critical for products with extreme seasonal variations

2. **Calculate Safety Stock**
   - Formula: Z-score × Standard Deviation × √Lead Time
   - Z-score = 1.65 for 95% service level

3. **Calculate Optimal Inventory Level**
   - Formula: (Average Daily Sales × Days Between Orders) + Safety Stock
   - Example: With 4 orders/year = 92 days of stock + safety stock

4. **Calculate Suggested Quantity**
   - Formula: Optimal Inventory - Effective Stock (including inbound stock)
   - Fills up to the optimal level

**In simple terms:** Pass 1 waits until you MUST order, Pass 2 orders BEFORE you run out.

### Reorder Point Formula
```
Reorder Point = (Average Daily Sales x Lead Time) + Safety Stock
```

### Safety Stock Formula
```
Safety Stock = Z x Standard Deviation x sqrt(Lead Time)
```

Where Z is the z-score for desired service level:
- 90% = 1.28
- 95% = 1.65
- 99% = 2.33

### Trend Adjustment
Recent sales (last 30 days) are weighted 70%, historical average 30%.

### Seasonal Analysis (Future-Looking)

The seasonal adjustment uses a sophisticated future-looking approach instead of just looking at the current month:

**How it works:**
1. **Calculate arrival date**: Today + supplier lead time
2. **Calculate coverage period**: Arrival date + days between orders
3. **Analyze future months**: Determines which months the order will cover
4. **Weight historical data**: Each month's historical sales weighted by coverage days
5. **Adjust forecast**: Applies weighted seasonal factor to daily sales estimate

**Example scenario:**
- Today: August 15
- Lead time: 60 days → Arrives October 15
- Orders/year: 4 → Covers 92 days
- Coverage: Oct 15 - Jan 15 (15 days Oct, 30 days Nov, 31 days Dec, 16 days Jan)
- Product sells 800 units Oct-Mar, near-zero Apr-Sep
- Result: Correctly forecasts high seasonal demand and orders accordingly

**Seasonal factor:**
- No dampening applied - uses raw historical data
- Capped at 0.5x-4.0x to prevent extreme values
- Global safety net: Total adjustments (trend + seasonal) capped at 0.4x-10x
- 10x cap allows extreme seasonal variations while protecting against data errors

**Pattern Validation (Trend vs Seasonality):**

Before applying seasonal adjustment, the algorithm validates that the pattern is consistent across years (not just trend growth). This prevents products with increasing sales from being misinterpreted as having seasonal peaks.

*How validation works:*
1. Get monthly sales data for each year separately
2. Calculate each month's percentage of that year's total sales
3. Compare patterns between consecutive years using Pearson correlation
4. Only apply seasonal adjustment if correlation ≥ 0.60

*Requirements for seasonal analysis:*
- Minimum 2 years of sales data
- At least 6 months with sales per year
- At least 12 units sold per year
- Pattern correlation ≥ 0.60 between years

*Example - True seasonality (correlation 0.85):*
```
Year 2023: Jan:5%, Feb:6%, Mar:8%, ... Sep:15%, Oct:12%, Nov:10%, Dec:8%
Year 2024: Jan:4%, Feb:7%, Mar:9%, ... Sep:14%, Oct:13%, Nov:11%, Dec:7%
→ Similar pattern each year = VALIDATED, seasonal factor applied
```

*Example - Trend growth (correlation 0.32):*
```
Year 2023: Jan:2%, Feb:3%, Mar:4%, ... Sep:10%, Oct:12%, Nov:15%, Dec:18%
Year 2024: Jan:8%, Feb:8%, Mar:9%, ... Sep:8%, Oct:9%, Nov:8%, Dec:9%
→ Pattern changed = SKIPPED, no seasonal adjustment (use trend instead)
```

**Benefits:**
- Prevents under-ordering before seasonal peaks
- Prevents over-ordering for off-season periods
- Essential for products with extreme seasonal variations (e.g., 800 units in 6 months, near-zero in 6 months)
- Accounts for long lead times (2-3 months)
- Raw seasonal factor captures true demand patterns without artificial conservative bias

## License

GPLv2 or later

## Author

[SereniSoft](https://serenisoft.no/)
