# FluentGift — Native Gift Card Add-on for FluentCart

**FluentGift** is a powerful, native Gift Card solution designed exclusively for FluentCart. It allows you to sell gift cards as products, automatically issue unique codes to customers, and provides a seamless redemption experience directly within the checkout flow.

Developed by **FluentSoloForge** (Amimul Ihsan Mahdi).

## 📺 Project Explanation & Walkthrough

Watch the full project walkthrough and explanation here:

[![FluentGift Walkthrough](https://img.youtube.com/vi/placeholder/0.jpg)](https://go.screenpal.com/watch/cTlwD5nr1Ue)

> **[Click here to watch the explanation video](https://go.screenpal.com/watch/cTlwD5nr1Ue)**

---

## 🚀 Key Features

### 🎁 Product & Admin Management
-   **Dedicated Gift Card Product Type**: Easily create gift cards using the new `gift_card` product type in FluentCart.
-   **Automated Coupon Generation**: The system automatically generates a "Template Coupon" in the background for each Gift Card product.
-   **Smart Configuration**: Automatically enforces "Simple Product" and "One-time Payment" settings to prevent configuration errors.

### 🛒 Purchase & Issuance
-   **Instant Issuance**: Unique gift codes are automatically generated and assigned to the customer immediately upon purchase.
-   **Email Ownership Validation**: Gift cards are locked to the purchaser's email (or designated recipient) to prevent unauthorized sharing.
-   **Customer Dashboard**: A dedicated **"My Gift Cards"** tab in the customer account area shows:
    -   Active Cards & Balances
    -   Redemption History
    -   One-click copy for codes

### 💳 Seamless Checkout Experience
-   **Integrated "Wallet" UI**:
    -   Automatically detects available gift cards for logged-in users.
    -   **One-Click Apply**: Apply gift cards directly from the list—no typing required.
-   **Smart UI Visibility**:
    -   **Auto-Hide**: The entire section hides if no cards are available.
    -   **Clean Interface**: Programmatically hides the standard "Have a Coupon?" field when Gift Cards are present to reduce clutter.
-   **Guest Support**: Guest users can manually enter gift codes via the standard coupon field.

### ⚙️ Robust Core Logic
-   **One-Time Access Model**: Cards function as "Access Tickets". Access is revoked upon full redemption to prevent double-spending.
-   **Refund Handling**:
    -   **Full Refunds**: Automatically restores gift card access.
    -   **Partial Refunds**: Intelligently manages access based on refund amount.
-   **Native Integration**: Built on top of FluentCart’s native Coupon and Order architecture for maximum stability and reporting compatibility.

---

## 📦 Installation

1.  Download the `fluent-gift.zip` file.
2.  Go to your WordPress Admin > **Plugins** > **Add New**.
3.  Click **Upload Plugin** and select the zip file.
4.  Activate **FluentGift**.

## 🔧 Configuration

1.  Go to **FluentCart > Products > Add New**.
2.  Select **Gift Card** as the product type.
3.  Set your price and publish.
4.  That’s it! Customers can now buy and redeem gift cards.

---

**FluentGift** — *Empowering FluentCart Stores.*
[aimahdi.com](https://aimahdi.com)
