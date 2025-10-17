# WooCommerce Super Bundle Pro  
**The Ultimate Product Bundling Plugin for WooCommerce**  

---

## Overview  
**WooCommerce Super Bundle Pro** is the most powerful and flexible WooCommerce bundle plugin designed for eCommerce stores looking to boost sales through customizable product bundles.  

Create **dynamic product bundles** with discounts, variation support, min/max item limits, and both **open bundles** (where customers choose items) and **closed bundles** (fixed packages). Perfect for **upselling**, **cross-selling**, and **increasing average order value**.  

This plugin is **SEO-optimized**, **lightweight**, and fully compatible with the latest WooCommerce versions.  
No coding required—set up bundles in minutes and watch your conversions soar!

---

## 🔑 Key Features

- **Customizable Bundles:**  
  Create *open bundles* (customers choose items) or *closed bundles* (fixed sets).  
  Supports min/max item limits for flexibility.

- **Smart Pricing:**  
  Use fixed pricing or auto-pricing (based on selected items) with percentage or flat discounts.  
  Prices update in real-time on the frontend.

- **Variation Support:**  
  Full support for WooCommerce variable products — let customers select options like size, color, or material.

- **Min/Max Constraints:**  
  Set minimum/maximum items or total value per bundle to prevent abuse.

- **Stock & Validation:**  
  Real-time stock checks, out-of-stock handling, and validation before adding to cart.

- **Cart Integration:**  
  Bundles display contents clearly in the cart. Supports bundled or separate tax/shipping calculations.

- **SEO-Friendly:**  
  Optimized for terms like “WooCommerce bundle plugin,” “product bundling for WooCommerce,” and “discount bundles WooCommerce.”

- **Mobile-Responsive:**  
  Fully responsive interface designed for seamless mobile performance.

- **Developer-Friendly:**  
  Clean codebase using WooCommerce core functions with hooks and filters for customization.

---

## 💡 Why Choose Super Bundle Pro?

Unlike other bundling plugins, **WooCommerce Super Bundle Pro** is:
- 💸 **Free and open-source**
- ⚙️ **Packed with pro-level features**
- 🛍️ **Ideal for all stores** — from dropshipping to digital and physical goods

---

## ⚙️ Installation

1. Download the plugin ZIP from **GitHub Releases**.  
2. In WordPress Admin:  
   `Plugins → Add New → Upload Plugin` → Upload the ZIP file.  
3. Click **Activate Plugin**.  
4. Ensure WooCommerce is installed and active.  
   - **Requirements:** WooCommerce 8.0+ and PHP 7.4+

That’s it — no database changes or complicated setup required.

---

## 🚀 Quick Start Guide

### Creating a Bundle (Admin)
1. Go to **Products → Add New**  
2. Select **Super Bundle Pro** as the product type.  
3. In the **Super Bundle Pro** tab:
   - Choose **Bundle Type:** Open (customer selects) or Closed (fixed)
   - Set **Price Mode:** Auto (sum + discount) or Fixed
   - Add bundle products and configure min/max limits
   - Apply any discounts
4. Click **Save Product**

### Frontend Experience
- Customers see a clean, customizable bundle form with product variations.  
- Real-time total and validation messages (e.g., *“Select at least 2 products”*).  
- Bundles are added to the cart seamlessly — no editing from the cart/checkout pages.

---

## 🧩 Customization

Use action hooks and filters like:
```php
wc_super_bundle_pro_add_to_cart_validation
