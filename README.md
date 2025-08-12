# pawaPay PHP SDK

A PHP SDK for integrating with the pawaPay API, enabling seamless payment processing, transaction management, and other key functionalities such as deposit, refund and payouts handling API calls.

> **Note:** V1 is the default main codebase, but V2 has also been integrated so both can work simultaneously.


## Folder structure

>example\direct
- Contains all curl sample requests with plain json raw output
> example
- Contains live or demo samples for each logic like deposit, refund and more. Both fontend and backend surgical logic samples.
> data
- Holds the configs for `mno_availability` and `active_conf` json files.

> src
- Holds the structure skeleton for pawaPay PHP SDK.
> tests
- Holds the test logics for the processes provided.

## Available Features

The pawaPay PHP SDK includes a comprehensive set of features designed to facilitate seamless payment integration with real-time verification:

- **Mobile Money Deposit Request**  
  ![Mobile Money Deposit](https://katorymnd.com/tqc_images/pawaPayDeposit.png)  
  The SDK allows for making deposit request to mobile money accounts with built-in real-time transaction verification. Each deposit request is processed and validated immediately to ensure its status is current and accurate.

- **Mobile Money Refund Request**
- ![Mobile Money Refund](https://katorymnd.com/tqc_images/pawaPayRefund.png)  
  Supports initiating refunds for previous `depositId` transactions. Refund requests are validated in real time, allowing you to check the status of each refund as soon as it’s submitted. However, availability of refund functionality depends on your account configuration.

- **Mobile Money Payout Request**
- ![Mobile Money Payout](https://katorymnd.com/tqc_images/pawaPayPayout.png)  
  Enables payouts to one or more recipients in a single transaction, with real-time verification of the payout status. This feature streamlines bulk payments and ensures all payout transactions are tracked in real time.

- **Real-Time Verification for All Transactions**  
  Whether making a deposit, issuing a refund, or processing a payout, the SDK ensures that all transactions are verified in real time, offering up-to-the-minute accuracy for all operations.

- **Country-Specific Payment Configuration**  
  The SDK dynamically fetches the list of supported Mobile Network Operators (MNOs) based on the country tied to your merchant account. This ensures that you only interact with active MNOs for deposits and payouts, preventing errors related to inactive operators.

- **Mobile Network Operator (MNO) Status Check**  
  Provides a real-time check on MNO availability, ensuring that deposits are only attempted when the operator is active. This helps avoid failed transactions due to inactive MNOs.

- **Owner Name Notification**  
  ![Owner Name Notification](https://katorymnd.com/tqc_images/pay-request-from.png)  
  The SDK now supports displaying notifications with the owner name for transactions, helping users identify payment requests sent on behalf of your organization.

- **Sandbox and Live Environments**  
  The SDK supports both sandbox and live environments, allowing for testing in a sandbox before moving to live transactions. You can easily switch between the two environments by setting the appropriate API tokens and SSL verification logic. Here’s an example:

  ```php
  // Set the environment (sandbox or production)
  $environment = getenv('ENVIRONMENT') ?: 'sandbox'; // Default to sandbox if not specified
  $sslVerify = $environment === 'production';  // SSL verification true in production

  // Dynamically construct the API token key
  $apiTokenKey = 'PAWAPAY_' . strtoupper($environment) . '_API_TOKEN';
  // Get the API token based on the environment
  $apiToken = $_ENV[$apiTokenKey] ?? null;
  ```

## Table of Contents

- [pawaPay PHP SDK](#pawapay-php-sdk)
  - [Available Features](#available-features)
  - [Table of Contents](#table-of-contents)
  - [Overview](#overview)
  - [Installation](#installation)
  - [Usage](#usage)
    - [Initializing the SDK](#initializing-the-sdk)
    - [Deposit via Payment Page](#deposit-via-payment-page)
    - [Update After Saving MNO Configuration](#update-after-saving-mno-configuration)
      - [1. Update the Country Dropdown in Your HTML](#1-update-the-country-dropdown-in-your-html)
      - [2. Configure the MNO Correspondents in JavaScript](#2-configure-the-mno-correspondents-in-javascript)
    - [Sending API Requests](#sending-api-requests)
  - [Tutorials and Guides](#tutorials-and-guides)
  - [Need Help?](#need-help)
  - [License](#license)

## Overview

The pawaPay PHP SDK provides a seamless integration of pawaPay's payment processing API with your PHP applications. It supports key functionalities such as deposit processing, real-time transaction verification, and refund handling, along with payouts to single or multiple recipients. All verifications are conducted in real time to ensure accurate and up-to-date transaction status.

Please note that the availability of certain features, such as country-specific payment options and refund capabilities, depends on the configuration of your merchant account. These settings may vary between sandbox and live environments.

> Requires PHP versions: >= 8.0.0.

## Installation

To install the SDK via Composer, run the following command:

```bash
composer require katorymnd/pawa-pay-integration
```
## Upgrading to Pawapay SDK V2
If you  have not yet installed the  SDK, install it and run `fetch_mno_conf.php` that file or  any file that you have created that  will add this `mno_availability.json`, `active_conf.json` . In our V2 the entire logic is  surgically created to use both v1 - default and v2, by your choice.

You can either use v1 or v2 but  v1 is  made default so that  your code does not break if you updated the  sdk but not  your code base.

When switching  to  v2/v1 you need to re-intiate the `fetch_mno_conf.php` with v1 as your choice, then it will create  these  files in `data` folder; `active_conf_v1.json`, `mno_availability_v1.json`, and v2 will create `active_conf_v2.json`, `mno_availability_v2.json` so  you  will have 3 files.

What the logic does is if  v1 is look for v1 or  our default json files and if  v2 it will load v2.
Run this  `example/fetch_mno_conf.php` and you will see that change.

Remove legacy files; the loader still works with versioned files only.


## Usage

### Initializing the SDK

- Environment Setup

After installing the SDK, you will need to configure your pawaPay API keys. Update the API keys securely in a `.env` file and save.

```bash
# .env
PAWAPAY_SANDBOX_API_TOKEN=your_sandbox_api_token_here
PAWAPAY_PRODUCTION_API_TOKEN=your_production_api_token_here
```

In your browser, load the following file:

```plaintext
example/fetch_mno_conf.php
```

This file will load your **MNO Availability and Active Configuration** via the API and save the details. The entire SDK will rely on this configuration to process transactions.

### Update After Saving MNO Configuration

Once you have saved your MNO configuration by loading `example/fetch_mno_conf.php`, you will need to update the country dropdown and MNO availability checks in your HTML files. This ensures the SDK reflects the correct configuration for processing deposits and payouts. Now V2 is enabled - you can choose V1/V2 logically.

#### 1. Update the Country Dropdown in Your HTML

You need to update the country dropdown in the relevant HTML files (e.g., `example/initiate_deposit.html`, `example/payouts.html`) to align with the countries assigned to your merchant account. Here’s an example of the updated dropdown:

```html
<label for="country" class="form-label">Country:</label>
<select id="country" name="country" required class="form-select">
  <option value="" disabled selected>Choose Country</option>
  <option value="Benin" data-currency="XOF">Benin</option>
  <option value="Burkina Faso" data-currency="XOF">Burkina Faso</option>
  <option value="Cameroon" data-currency="XAF">Cameroon</option>
  <option value="Congo" data-currency="XAF">Congo-Brazzaville</option>
  <option value="Congo (DRC)" data-currency="CDF">Congo-Kinshasa (CDF)</option>
  <option value="Congo (DRC)" data-currency="USD">Congo-Kinshasa (USD)</option>
  <option value="Cote D'Ivoire" data-currency="XOF">Cote D'Ivoire</option>
  <option value="Gabon" data-currency="XAF">Gabon</option>
  <option value="Ghana" data-currency="GHS">Ghana</option>
  <option value="Kenya" data-currency="KES">Kenya</option>
  <option value="Malawi" data-currency="MWK">Malawi</option>
  <option value="Mozambique" data-currency="MZN">Mozambique</option>
  <option value="Nigeria" data-currency="NGN">Nigeria</option>
  <option value="Rwanda" data-currency="RWF">Rwanda</option>
  <option value="Senegal" data-currency="XOF">Senegal</option>
  <option value="Sierra Leone" data-currency="SLE">Sierra Leone</option>
  <option value="Uganda" data-currency="UGX">Uganda</option>
  <option value="Zambia" data-currency="ZMW">Zambia</option>
</select>
```
`example\initiate_deposit.html` we added 
```script
/* ===========================
       * Version-aware config loader
       * =========================== */
      const confVersion = "v1";
```
so  you  need to change the version from v1 to v2. v1 will load the  default ui for the operator images as of `_v1.json` and v2 will load all the logics from v2, as of  `_v2.json` files in the  `data` folder.

Also its  backend file is compatible with v1/v2. Change accordingly.

The logic also applies to `example\payouts.html` and its backend logic too.

#### 2. Configure the MNO Correspondents in JavaScript

Once you've updated the country dropdown, you'll also need to configure the MNO status dynamically based on the saved MNO configuration. Here's an example of how to manage the MNOs for each country in JavaScript:

```javascript
const mnoCorrespondents = {
  Benin: [
    {
      name: "MTN Benin",
      apiCode: "MTN_MOMO_BEN",
      countryCode: "+229",
      flag: "bj.png",
      available: true,
      img: "mtn.png",
    },
    {
      name: "Moov Benin",
      apiCode: "MOOV_BEN",
      countryCode: "+229",
      flag: "bj.png",
      available: true,
      img: "moov.png",
    },
  ],
  "Burkina Faso": [
    {
      name: "Orange Burkina Faso",
      apiCode: "ORANGE_BFA",
      countryCode: "+226",
      flag: "bf.png",
      available: true,
      img: "orange-money-logo.jpg",
    },
    {
      name: "Moov Burkina Faso",
      apiCode: "MOOV_BFA",
      countryCode: "+226",
      flag: "bf.png",
      available: false,
      img: "moov.png",
    },
  ],
  Uganda: [
    {
      name: "MTN Uganda",
      apiCode: "MTN_MOMO_UGA",
      countryCode: "+256",
      flag: "ug.png",
      available: true,
      img: "mtn.png", // Image name for MTN Uganda
    },
    {
      name: "Airtel Uganda",
      apiCode: "AIRTEL_OAPI_UGA",
      countryCode: "+256",
      flag: "ug.png",
      available: true,
      img: "airtel.png", // Image name for Airtel Uganda
    },
  ],
  // Add more countries and MNOs here...
};
```
#### 3. Configure ISO3 for v2 country metadata in JavaScript
```javascript

 // Map UI country names -> ISO3 for v2 country metadata
      // Update the Map UI according to what countries your given with pawapay account
      const COUNTRY_TO_ISO3 = {
        Benin: "BEN",
        "Burkina Faso": "BFA",
        Cameroon: "CMR",
        Congo: "COG",
        "Congo (DRC)": "COD",
        "Cote D'Ivoire": "CIV",
        Gabon: "GAB",
        Ghana: "GHA",
        Kenya: "KEN",
        Malawi: "MWI",
        Mozambique: "MOZ",
        Nigeria: "NGA",
        Rwanda: "RWA",
        Senegal: "SEN",
        "Sierra Leone": "SLE",
        Tanzania: "TZA",
        Uganda: "UGA",
        Zambia: "ZMB",
      };

```
I added a version control 
```javascript

  // pick one: 'v1' | 'v2' | 'auto'  (local default)
  const confVersion = "v2";
  ```
When you choose `auto` the script will decide accordingly.


The functionaliy is:

- When the user selects a country, the logic dynamically updates the list of available Mobile Network Operators (MNOs) for that country.
- The MNO options are based on the `mnoCorrespondents` object, which contains details like MNO name, API code, availability, and country code.
- If an MNO is marked as unavailable (`available: false`), its option is disabled to prevent users from selecting it.

### Sending API Requests

To test different functionalities of the pawaPay SDK, follow these steps:

- **Deposit Request**: To test the deposit functionality, open the following page in your browser:

  ```plaintext
  initiate_deposit.html
  ```

- **Refund Logic**: To test the refunds logic, open the following page in your browser:

  ```plaintext
  refund_form.html
  ```

- **Payouts**: To test the payouts functionality, open the following page in your browser:

  ```plaintext
  payouts.html
  ```

## Deposit via Payment Page

Use the hosted widget to collect payment with a single redirect. Your app
creates a session and receives a `redirectUrl` to send the customer to.

> Make sure your `.env` has:
> ```
> PAWAPAY_SANDBOX_API_TOKEN=your_sandbox_api_token_here
> PAWAPAY_PRODUCTION_API_TOKEN=your_production_api_token_here
> ENVIRONMENT=sandbox
> ```

### Full example

Find the full example here `example\deposit-via-payment-pag.php` and v1 is default. you may switch like  so
```php

// Choose API version: 'v1' or 'v2' (default to 'v1' to preserve old behavior)
$API_VERSION = 'v1';
```


For testing, please use the provided test phone numbers (MSISDNs) available in the official pawaPay documentation:

This is a sample page, you can add the  details from the  form data or  just add defaultly. Then create a button that has the got link so that the client is redirected to complete the peyment. 
 
 
This `https://example.com/paymentProcessed` replace with your redirect url and the return will be like `https://example.com/paymentProcessed?depositId=c4a7a044-5ca0-415b-a236-423f11e1e1e8` where you can use the  `depositId` to verify the payment.



[Testing Phone Numbers](https://docs.pawapay.io/testing_the_api)

> **Important**: Refunds can only be submitted once per deposit ID. Attempting to refund the same deposit ID multiple times will result in an error.

> Additionally, every transaction—whether successful or not—will be logged in detail in the `log` files. You can refer to these log files for an in-depth analysis of any transaction, ensuring you have a complete record for debugging and auditing purposes.

> **Note:** Some providers are slow hence the transaction is in `processing` mode. Always check the `transactionId` before making any transaction as `completed`.

## Tutorials and Guides

Explore these resources to get started and make the most of the pawaPay Payment SDK:

1. **[Getting Started With the pawaPay SDK: Installation and Setup](https://katorymnd.com/article/getting-started-with-the-pawapay-sdk-installation-and-setup)**  
   A beginner's guide to installing and setting up the SDK.

2. **[How to Configure and Integrate pawaPay SDK: A Step-by-Step Guide](https://katorymnd.com/article/how-to-configure-pawapay-sdk-a-step-by-step-guide)**  
   Detailed instructions on configuring and integrating the SDK into your PHP application.

3. **[Transparency in Payments: Owner Name Alerts in Pawapay Sdk](https://katorymnd.com/article/transparency-in-payments-owner-name-alerts-in-pawapay-sdk)**  
   Explore how the pawaPay PHP SDK enhances transparency in mobile payments with Owner Name notifications, ensuring user confidence and clarity.

## 🛠️ Need Help?

I'm happy to provide **general guidance** or **clarity** regarding the pawaPay PHP SDK. However, please note that **custom integrations**, **advanced support**, or **SDK development in other programming languages** are offered **as a paid service**.

### ✅ Support Includes:

- Installation help and usage of **this SDK**.
- Guidance on using provided SDK methods (e.g., `initiateDeposit()`, `initiateRefund()`, `initiatePayout()`).
- Bug reports or improvements related strictly to this repository.

### ❌ Support Does NOT Include:

- Errors from other SDKs or third-party codebases.
- Debugging issues not related to this SDK.
- Creating SDKs in other programming languages (Node.js, Python, C#, etc.) — this qualifies as **custom development**.

---

## 💰 Paid Support Policy

- A **clear description** of your project is required before any support is provided.
- **Full payment upfront** is required for all custom or advanced support services.
- **No split or partial payments** are accepted for ongoing support or consultations.

---

### 📩 Request Paid Support or a Custom SDK

To ensure fair and professional collaboration, please complete the following form **before requesting any support**:

👉 [**Submit a Support Request**](https://katorymnd.com/pawapay-sdk-support-request)

This form helps clarify:

- What SDK or platform you're using
- Whether you're using **this SDK**
- Whether you're requesting free guidance or **paid custom support**

After submitting the form, feel free to reach out:

**Contact:** [katorymnd@gmail.com](mailto:katorymnd@gmail.com)

> _Thank you for respecting these boundaries. They help me provide faster, high-quality assistance to those using this SDK for its intended purpose._

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.
