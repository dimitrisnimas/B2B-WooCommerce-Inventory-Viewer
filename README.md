# 📦 Modern Inventory Management System (SPA)

A lightweight, secure, and responsive Single Page Application (SPA) for real-time inventory management. This project demonstrates a decoupled architecture connecting a static frontend to a Headless WordPress backend via secure REST APIs.

## 🚀 Key Features

*   **⚡ Real-Time Search**: Instant server-side search results as you type (debounced).
*   **📱 Mobile-First Design**: Custom "Card View" for mobile devices, eliminating horizontal scrolling.
*   **🖼️ Responsive Modal**: Full-screen product details with gallery, prices, and stock status.
*   **🔒 Secure Authentication**:
    *   **Frontend**: Client-side login overlay for portfolio demo protection.
    *   **Backend**: API Key authentication (`X-INVENTORY-KEY`) and Basic Auth integration.
    *   **CI/CD**: Secrets injected at build time via GitHub Actions (no hardcoded credentials).
*   **📊 Dynamic Pricing**: Supports multi-tier pricing (Retail, Wholesale/B2B Groups).

## 🛠️ Architecture

*   **Frontend**: Vanilla HTML5, CSS3, JavaScript (ES6+). Zero build tools required for local dev, but deployed via CI/CD.
*   **Backend (Headless)**: WordPress + WooCommerce with Custom API Endpoints.
*   **Security**: API Keys are never exposed in the source code. They are injected as environment variables during the GitHub Actions build process.

## 📱 Mobile Experience

The application features a custom responsive engine:
*   **Desktop**: Full data table with sorting and quick views.
*   **Mobile**: Transforms rows into interactive "Cards" with `object-fit: contain` images and stackable details.

## ⚙️ Configuration & Deployment

### 1. Variables
This project uses **GitHub Actions variables** to inject configuration at runtime:
*   `API_URL`: The endpoint of the backend inventory API.
*   `API_KEY`: The secure secret key for API access.
*   `AUTH_USER`: Username for the frontend demo lock.
*   `AUTH_PASS`: Password for the frontend demo lock.

### 2. Local Development
To run locally, you can simply open `index.html`. 
*   *Note*: You will need to manually replace the `{{VARIABLES}}` in the code or use a local `config.js` shim for testing.

---

*This project is a sanitized version of a production system deployed for a high-volume auto parts distributor.*
