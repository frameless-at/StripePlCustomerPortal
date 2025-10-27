# StripePaymentLinks Customer Portal (ProcessWire)

A lightweight companion module for **StripePaymentLinks (SPL)** that adds a ready-to-use customer page at **`/account/`** plus small developer helpers (login/account/logout link & profile modal). It integrates with SPL’s own login modal and redirect flow – no duplicate auth.

![Customer portal grid view](img/account_grid.png)

---

## 1) Requirements

- **ProcessWire** 3.0.210+
- **StripePaymentLinks** (SPL) installed & configured
- **Bootstrap** available on the front-end (SPL can load it automatically)

**Product pages expected by the grid:**
- Must live on templates listed in SPL → `productTemplateNames`
- Must set the **checkbox/flag** `requires_access=1`
- (Optional but recommended) have an **Images** field  
  The grid will use the **first image** as the product thumbnail:
  - if the field doesn’t exist or is empty, the card simply has no image

---

## 2) What this module installs

- Template **`spl_account`** (fieldgroup **`fg_spl_account`**)
- Page **`/account/`** using `spl_account`
- Template file **`/site/templates/spl_account.php`** with a minimal layout that calls the portal methods  
  (You can customize this file freely.)

---

## 3) Quick start

Add a login/account/logout link anywhere in your templates:

```
$modules->get('StripePlCustomerPortal')->renderLoginLink([
  'class' => 'nav-link text-white', // optional
  // 'label' => 'Custom label'      // optional — overrides i18n default
]);
```

**Behavior**
- Logged out → shows **“Customer Login”** that opens SPL’s `#loginModal` and sets the intended redirect to `/account/`.
- Logged in on `/account/` → shows **“Logout”** (appends `?spl_logout=1`).
- Logged in elsewhere → shows **“My Account”** linking to `/account/`.

The module auto-creates `/account/`. You can link to it or place the button in your site header.

---

## 4) Account page usage

The file `/site/templates/spl_account.php` is created for you and calls the module’s renderer. It shows:
- page header with **view switcher** and **“Edit my data”** button
- the **grid** of purchased products (and optional “not yet purchased” items)
- a profile **edit modal** (AJAX + CSRF)

Switch views (grid / table / grid-all) via querystring:

- `/account/?view=grid-all` (default used by the template)
- `/account/?view=grid`
- `/account/?view=table`

You can also render the header buttons anywhere:

```
$modules->get('StripePlCustomerPortal')->renderHeaderButtons('grid-all');
```

---

## 5) Product data used by the grid (important)

The grid is built from the user’s SPL purchases and your product pages. For **each product**:

- **Title**: page title
- **URL**: page URL (only linked when `requires_access=1`)
- **Status badge**: derived from SPL’s period/paused/canceled metadata
- **Thumbnail**: **first image** of the **`images`** field  
  → If you want images in the cards, add an **Images** field named **`images`** to your product templates.  
  → If the field is missing or empty, the card renders without an image.

---

## 6) Internationalization (i18n)

All UI texts live in the module’s `i18n()` and are picked up by ProcessWire’s Language tools:

- Link labels: `link.login`, `link.logout`, `link.account`
- Grid / table headings, status labels, profile modal labels, etc.

The module also overrides SPL’s `t()` **only** when the intended URL points to `/account/`, so you can present custom login modal texts for the portal flow.

---

## 7) Screenshots

**Grid view**  
![Grid](img/account_grid.png)

**Table view**  
![Table](img/account_table.png)

**Edit profile modal**  
![Edit data](img/account_edit-data.png)

---

## 8) Uninstall

- Removes `/account/` page and `spl_account` template.
- Leaves `/site/templates/spl_account.php` in place (in case you customized it).

---

## 9) License

MIT.
