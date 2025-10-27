# StripePaymentLinks Customer Portal (ProcessWire)

A thin companion module for [StripePaymentLinks (SPL)](https://github.com/) that provides a customer account page at `/account/` and convenient login/account/logout links that respect SPL’s login modal and redirect flow.

![Example of a logged in user visiting his account](img/account_grid.png)

Key features
- Ready-made customer portal — automatically creates a functional /account/ page showing all purchased and available digital products.
- Seamless integration with StripePaymentLinks — uses SPL’s existing login modal, magic-link system, and API; no duplicate auth logic.
- One-click access for users — login, logout, and “My Account” links adapt automatically depending on user state and context.
- Editable customer profile — includes a built-in “Edit my data” modal with CSRF protection and AJAX submission.
- Customizable layout — provides grid, table, and mixed “grid-all” views with Bootstrap styling.
- Fully translatable — all interface texts defined via i18n() and editable through ProcessWire’s Language system.

---

## Requirements
- ProcessWire 3.0.210+
- StripePaymentLinks module (a version that provides the login modal and `t()` translations)
- Bootstrap (SPL’s modal depends on it, optionally auto injected by SPL)

---

## What the module installs
- Template `spl_account` (with fieldgroup `fg_spl_account`)
- Page `/account/` using `spl_account`
- Template file `/site/templates/spl_account.php` with all necessary UI elements. You may customize `spl_account.php` as needed.

---

## Usage in templates

### 1) Login / Account / Logout link
Render a single link that adapts to user state and location:

```php
<?php
echo $modules->get('StripePlCustomerPortal')->renderLoginLink([
  'class' => 'nav-link text-white', // optional
  // 'label' => '…'                  // optional, overrides i18n default
]);
?>
```

Behavior:
- Logged out → “Customer Login” link opens SPL’s `#loginModal`; sets `pl_intended_url` to `/account/`.
- Logged in on `/account/` → “Logout” link (appends `?spl_logout=1`).
- Logged in elsewhere → “My Account” linking to `/account/`.

### 2) Account page content
`renderAccount()` outputs the purchases as grid or table:

![Example of a logged in user visiting his account, table view](img/account_table.png)


### 3) Optional header buttons

```php
<?php
echo $modules->get('StripePlCustomerPortal')->renderHeaderButtons('grid-all');
?>
```

Shows a compact grid/table view switcher and an “Edit my data” button.

![Example of a logged in user visiting his account, edit data modal](img/account_edit-data.png)

---

## Internationalization (i18n)
All strings are defined via `tLocal()` and can be translated using ProcessWire’s Language tools. The module also overrides SPL’s `t()` only when `pl_intended_url` points to `/account/`, allowing custom modal texts for the portal flow.


---

## Troubleshooting
- Modal doesn’t open on `/account/`  
  Ensure your theme outputs SPL’s `#loginModal` markup (SPL should include it), and Bootstrap JS is present. Also verify the page actually has `</body>` so the injector can append the script.

- Not redirected to `/account/` after login  
  Confirm `pl_intended_url` is set to `/account/` at click-time. The module sets it in `renderLoginLink()` for logged-out users and in `renderAccount()` when the page is requested. Also ensure SPL reads `pl_intended_url`.

- Modal opens on other pages unexpectedly  
  The module’s auto-open runs only on `/account/`. If a different page opens a separate “login required” modal, that likely comes from SPL’s product gating and is expected (separate from this module).

---

## Security notes
- Profile updates are protected by ProcessWire’s CSRF (`$session->CSRF`).
- Redirect sanitization: return_url validation prevents external hosts.
- Logout uses ProcessWire’s `$session->logout()`.

---

## Uninstall
- Removes `/account/` and the `spl_account` template.
- Leaves the physical `/site/templates/spl_account.php` in place (you may have customized it).


---

## License
MIT. Use at your own risk.