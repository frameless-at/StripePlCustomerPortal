StripePaymentLinks Customer Portal (ProcessWire)

A thin companion module for StripePaymentLinks (SPL) that provides a customer account page at /account/, plus convenient login/account/logout links that respect SPL’s login modal and redirect flow.
	•	No redirects for logged-out users: hitting /account/ opens SPL’s login modal instead of redirecting away.
	•	Post-login redirect: after successful login, users are sent to /account/ (via pl_intended_url).
	•	Clean UI helpers: render login/account/logout links and header buttons.
	•	i18n ready: all texts are translatable via ProcessWire’s translation tools.

⸻

Requirements
	•	ProcessWire 3.0.210+
	•	StripePaymentLinks module (current version that provides the login modal and t() translations)
	•	Bootstrap (the SPL modal already depends on it)

⸻

What the module installs
	•	Template spl_account (with fieldgroup fg_spl_account)
	•	Page /account/ using spl_account
	•	Template file /site/templates/spl_account.php with:

<?php namespace ProcessWire;
$portal = $modules->get('StripePlCustomerPortal');
$content = $portal->renderAccount();

You may customize spl_account.php as needed.

⸻

How it works
	•	When a logged-out visitor requests /account/, the module:
	•	sets pl_open_login=1 and pl_intended_url=/account/,
	•	injects a tiny script after render to open SPL’s login modal on that page.
	•	When login completes via SPL, users are redirected to /account/ by SPL (reading pl_intended_url).
	•	On logout (?spl_logout=1 on /account/) the module logs out via PW’s Session and redirects to /.

The module does not replace SPL’s login—it rides on top of it and only orchestrates the intended URL + auto-open behavior for /account/.

⸻

Usage in templates

1) Login / Account / Logout link

Render a single link that adapts to user state + location:

<?php echo $modules->get('StripePlCustomerPortal')->renderLoginLink([
  'class' => 'nav-link text-white',      // optional
  // 'label' => '…'                      // optional, overrides i18n default
]); ?>

Behavior:
	•	Logged out → “Customer Login” link opens SPL’s #loginModal; sets pl_intended_url to /account/.
	•	Logged in on /account/ → “Logout” link (appends ?spl_logout=1).
	•	Logged in elsewhere → “My Account” linking to /account/.

2) Account page content

renderAccount() outputs the purchases grid/table and the profile modal trigger:

<?php echo $modules->get('StripePlCustomerPortal')->renderAccount(/* 'grid' | 'table' | 'grid-all' */); ?>

If the user is not logged in, the method returns a slim container; the modal is opened automatically on /account/.

3) Optional header buttons

<?php echo $modules->get('StripePlCustomerPortal')->renderHeaderButtons('grid-all'); ?>

Shows a compact grid/table view switcher and an “Edit my data” button.

⸻

Internationalization (i18n)

All strings are defined via tLocal() and can be translated in ProcessWire’s Language tools. The module also overrides SPL’s t() only when pl_intended_url points to /account/, so your custom modal title/body apply in that context.

⸻

Hooks & session flags (for reference)
	•	Page::render (after): injects a script to open #loginModal only on /account/ when pl_open_login=1. The flag is consumed (removed) after use.
	•	StripePaymentLinks::t (after): adjusts SPL modal texts when pl_intended_url starts with /account/.
	•	GET ?spl_logout=1 on /account/: logs out, clears pl_open_login / pl_intended_url, redirects to /.

Session flags used
	•	pl_intended_url (string): target after login (e.g. /account/).
	•	pl_open_login (bool-ish): one-time indicator to auto-open the modal on /account/.

⸻

Troubleshooting
	•	Modal doesn’t open on /account/
Ensure your theme outputs SPL’s #loginModal markup (SPL should include it), and Bootstrap JS is present. Also verify the page actually has </body> so the injector can append the script.
	•	Not redirected to /account/ after login
Confirm pl_intended_url is set to /account/ at click-time (the module sets it in renderLoginLink() for logged-out users and in renderAccount() when the page is requested). Also ensure SPL is reading that session key (current versions do).
	•	Modal opens on other pages unexpectedly
The module’s auto-open runs only on /account/. If a different page opens a separate “login required” modal, that likely comes from SPL’s product gating. That’s expected and separate from this module.

⸻

Security notes
	•	Profile updates are protected by ProcessWire’s CSRF ($session->CSRF).
	•	Redirect sanitization: return_url validation prevents external hosts.
	•	Logout uses ProcessWire’s $session->logout().

⸻

Uninstall
	•	Removes /account/ and (if unused) the spl_account template.
	•	Leaves the physical /site/templates/spl_account.php in place (you may have customized it).

⸻

Changelog
	•	0.1.1
	•	i18n keys for link.login, link.logout, link.account
	•	Robust post-render modal injector for /account/
	•	Cleaned comments & minor safeguards
	•	0.1.0 Initial release

⸻

License

MIT. Use at your own risk.