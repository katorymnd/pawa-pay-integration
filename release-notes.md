##  What's New in v4.4.0

This release introduces **full V2 compatibility** while keeping V1 as the default, ensuring a smooth transition for existing integrations. We’ve also added a **hosted payment page** option that works with both V1 and V2, making payment collection even easier.

###  Key Updates
- **Dual-version support**: V1 remains default, but V2 is fully integrated and can be enabled instantly.
- **Hosted Payment Page integration**:
  - Works seamlessly with both V1 and V2.
  - Simplifies collecting payments with a single redirect.
- **Configuration improvements**:
  - Added new `active_conf_v1.json` and `active_conf_v2.json`.
  - Added MNO availability JSONs for both versions.
- **Code enhancements**:
  - Updated `ApiClient` and `FailureCodeHelper` for V2 handling.
  - Examples and tests updated for dual-version compatibility.
- **Documentation**:
  - Updated `README.md` and `composer.json` to reflect the new features.

---

###  Installation / Update

**Fresh install**:
```bash
composer require katorymnd/pawa-pay-integration:^4.4
