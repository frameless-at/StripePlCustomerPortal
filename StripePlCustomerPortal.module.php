<?php namespace ProcessWire;

use ProcessWire\Page;
use ProcessWire\User;
use ProcessWire\WireData;
use ProcessWire\Module;

/**
 * StripePlCustomerPortal
 *
 * Provides a customer overview page rendered via a real template (/site/templates/spl_account.php).
 * - Creates template "spl_account" and page "/account" on install.
 * - Template file prints $modules->get('StripePlCustomerPortal')->renderAccount();
 *
 * Requires: ProcessWire 3.0.210+, StripePaymentLinks.
 */
class StripePlCustomerPortal extends WireData implements Module {

  /**
   * Module metadata.
   *
   * @return array
   */
  public static function getModuleInfo(): array {
    return [
      'title'    => 'StripePaymentLinks Customer Portal',
      'version'  => '0.1.2',
      'summary'  => 'Customer overview at /account using a dedicated template (spl_account).',
      'author'   => 'frameless Media',
      'autoload' => true,
      'singular' => true,
      'requires' => ['ProcessWire>=3.0.210', 'StripePaymentLinks'],
      'icon'     => 'user-circle',
    ];
  }

  /* ========================= Lifecycle ========================= */

  /**
   * Initialize module: ensure template + page exist and attach hooks.
   *
   * Ensures the account template and page exist after module refresh/upgrades.
   *
   * @return void
   */
  public function init(): void {
    $this->ensureAccountTemplateAndPage();

    $this->addHookBefore('Page::render', function(\ProcessWire\HookEvent $e) {
      $input   = $this->wire('input');
      $session = $this->wire('session');
      $config  = $this->wire('config');

      if ($input->get('spl_logout')) {
        $session->logout();
        $session->remove('pl_open_login');
        $session->remove('pl_intended_url');
        $session->redirect($config->urls->root); // after logout redirect to /
      }
    });

    // API endpoint hook for profile updates
    $this->addHook('/stripepaymentlinks/api', function(\ProcessWire\HookEvent $e) {
      $this->handleProfileUpdate($e);
    });

    // If user not logged in and the account page requested opening the login modal,
    // inject JS after Page::render to open SPL's login modal automatically.
    $this->addHookAfter('Page::render', function(\ProcessWire\HookEvent $e) {
      $session = $this->wire('session');
      $user    = $this->wire('user');
      $page    = $this->wire('page');

      if ($user->isLoggedin()) {
        $session->remove('pl_open_login');
        return;
      }

      // Only automatically open on /account/
      if ($page->path !== '/account/' || !$session->get('pl_open_login')) return;

      $html = (string) $e->return;
      $session->remove('pl_open_login'); // one-time flag

      if (strpos($html, 'id="loginModal"') !== false) {
        $js = '<script>
        document.addEventListener("DOMContentLoaded", function () {
          var el = document.getElementById("loginModal");
          if (el && window.bootstrap) {
            bootstrap.Modal.getOrCreateInstance(el).show();
          }
        });
        </script>';
        $e->return = preg_replace('~</body>~i', $js . '</body>', $html, 1);
      }
    });

    // Override SPL texts for login modal when the intended URL is /account/
    $this->addHookAfter('StripePaymentLinks::t', function($e) {
      $session  = $this->wire('session');
      $intended = (string) $session->get('pl_intended_url'); // set in renderAccount()
      if ($intended === '' || strpos($intended, '/account/') === false) return;

      $key = (string) $e->arguments(0);
      if ($key === 'modal.login.title') { $e->return = $this->tLocal('modal.login.title'); return; }
      if ($key === 'modal.login.body')  { $e->return = $this->tLocal('modal.login.body');  return; }
    });
  }

  /**
   * Create template, template file and page on install.
   *
   * @return void
   */
  public function ___install(): void {
    $this->ensureAccountTemplateAndPage(true);
  }

  /**
   * Keep things intact on upgrade.
   *
   * @param mixed $from
   * @param mixed $to
   * @return void
   */
  public function ___upgrade($from, $to): void {
    $this->ensureAccountTemplateAndPage(true);
  }

  /**
   * Optional clean-up on uninstall: remove page; drop template if unused.
   *
   * The physical template file is left untouched (it may have been customized).
   *
   * @return void
   */
  public function ___uninstall(): void {
    $pages     = $this->wire('pages');
    $templates = $this->wire('templates');

    if ($p = $pages->get('/account/')) {
      try { $pages->delete($p, true); } catch (\Throwable $e) { /* ignore */ }
    }

    if ($tpl = $templates->get('spl_account')) {
      // delete only if no pages use it anymore
      if (!$pages->count("template=spl_account")) {
        try { $templates->delete($tpl); } catch (\Throwable $e) { /* ignore */ }
      }
    }
    // Leave the physical file untouched (may have been modified).
  }

  /**
   * Helper: main StripePaymentLinks module instance.
   *
   * @return \ProcessWire\StripePaymentLinks
   */
  private function spl(): \ProcessWire\StripePaymentLinks {
    /** @var \ProcessWire\StripePaymentLinks $mod */
    $mod = $this->modules->get('StripePaymentLinks');
    return $mod;
  }

  /**
   * Local UI texts (internationalization wrapper).
   *
   * @return array
   */
  private function i18n(): array {
    return [
      // Headings / UI
      'ui.purchases.title'    => $this->_('Your purchases'),
      'ui.table.no_purchases' => $this->_('No purchases found.'),
      'ui.table.head.date'    => $this->_('Date'),
      'ui.table.head.product' => $this->_('Product'),
      'ui.table.head.status'  => $this->_('Status'),

      // Button
      'button.edit' => $this->_('Edit my data'),

      // Profile modal
      'profile.title'          => $this->_('Edit my data'),
      'profile.intro'          => $this->_('Update your account information below.'),
      'profile.save'           => $this->_('Save'),
      'profile.cancel'         => $this->_('Cancel'),
      'label.name'             => $this->_('Full name'),
      'label.password'         => $this->_('Password'),
      'label.password_confirm' => $this->_('Confirm password'),

      // Login-required text (used to override SPL’s t())
      'modal.login.title' => $this->_('Customer Login'),
      'modal.login.body'  => $this->_('Please sign in to view your purchases.'),

      // Status strings (with placeholders)
      'status.active_until'   => $this->_('Active until {date}'),
      'status.expired_on'     => $this->_('Expired on {date}'),
      'status.paused'         => $this->_('Paused'),
      'status.canceled'       => $this->_('Canceled'),
      'status.canceled_until' => $this->_('Canceled (until {date})'),

      // API responses (local)
      'api.csrf_invalid'    => $this->_('Invalid CSRF'),
      'api.not_signed_in'   => $this->_('Not signed in.'),
      'api.profile_updated' => $this->_('Profile updated.'),

      // Navigation
      'link.login'   => $this->_('Customer Login'),
      'link.logout'  => $this->_('Logout'),
      'link.account' => $this->_('My Account'),
    ];
  }

  /**
   * Get a local text by key.
   *
   * @param string $key
   * @return string
   */
  private function tLocal(string $key): string {
    static $L = null;
    if ($L === null) $L = $this->i18n();
    return $L[$key] ?? '';
  }

  /**
   * Tiny formatter: replaces {token} with given values.
   *
   * @param string $key
   * @param array $repl
   * @return string
   */
  private function tLocalFmt(string $key, array $repl): string {
    $txt = $this->tLocal($key);
    return strtr($txt, $repl);
  }

  /**
   * All online products that require access (based on configured product templates).
   *
   * @return \ProcessWire\PageArray
   */
  private function findAccessProducts(): \ProcessWire\PageArray {
    $pages  = $this->wire('pages');
    $san    = $this->wire('sanitizer');
    $tpls   = $this->getProductTemplateNames();
    $tplSel = $tpls ? ('template=' . implode('|', $tpls) . ', ') : '';
    // if no templates are configured, still search for requires_access
    $sel = $tplSel . 'requires_access=1, include=hidden, sort=-created';
    return $pages->find($sel);
  }

  /**
   * Create/verify template + file + page.
   *
   * @param bool $writeFile If true, write the template file even if it exists.
   * @return void
   */
   private function ensureAccountTemplateAndPage(bool $writeFile = false): void {
     $templates   = $this->wire('templates');
     $fieldgroups = $this->wire('fieldgroups');
     $pages       = $this->wire('pages');
     $config      = $this->wire('config');
     $roles       = $this->wire('roles');
   
     // --- 1) Fieldgroup: get or create
     /** @var \ProcessWire\Fieldgroup|null $fg */
     $fg = $fieldgroups->get('fg_spl_account');
     if (!$fg || !$fg->id) {
       $fg = new \ProcessWire\Fieldgroup();
       $fg->name = 'fg_spl_account';
       $fieldgroups->save($fg);
     }
   
     // --- 2) Template: get or create
     /** @var \ProcessWire\Template|null $tpl */
     $tpl = $templates->get('spl_account');
     if (!$tpl || !$tpl->id) {
       $tpl = new \ProcessWire\Template();
       $tpl->name       = 'spl_account';
       $tpl->fieldgroup = $fg;
       $tpl->noChildren = 1;
       $tpl->useRoles   = 1;
       $templates->save($tpl);
   
       // Default access setup (only applied on creation)
       $guest    = $roles->get('guest');
       $customer = $roles->get('customer');
   
       if ($guest && method_exists($tpl, 'removeRole')) $tpl->removeRole($guest, 'view');
       if ($customer && method_exists($tpl, 'addRole'))  $tpl->addRole($customer, 'view');
   
       $tpl->set('noAccess', 2);        // redirect/render
       $tpl->set('redirectLogin', '/'); // redirect target
       $templates->save($tpl);
     } else {
       // Ensure correct fieldgroup is assigned, but do NOT override access
       if (!$tpl->fieldgroup || $tpl->fieldgroup->id !== $fg->id) {
         $tpl->fieldgroup = $fg;
         $templates->save($tpl);
       }
     }
   
     // --- 3) Template file /site/templates/spl_account.php
     $tplFile = rtrim($config->paths->templates, '/\\') . DIRECTORY_SEPARATOR . 'spl_account.php';
     if ($writeFile || !is_file($tplFile)) {
       $code = <<<'PHP'
   <?php namespace ProcessWire;
   /** @var \ProcessWire\Modules $modules */
   $portal = $modules->get('StripePlCustomerPortal');
   
   $content = '
   <div class="container py-5 mt-5">
     <div class="row">
       <div class="col-lg-10 mx-auto">
         <div class="d-flex align-items-center justify-content-between mb-3">
           <h1 class="mb-0">Hello, ' . $user->title . '</h1>
           ' . $portal->renderHeaderButtons() . '
         </div>
       </div>
     </div>
   </div>';
   
   $content .= $portal->renderAccount('grid-all');
   PHP;
       if (is_dir($config->paths->templates)) {
         @file_put_contents($tplFile, $code, LOCK_EX);
         @chmod($tplFile, 0660);
       }
     }
   
     // --- 4) Page /account: create if missing
     $account = $pages->get('/account/');
     if (!$account || !$account->id) {
       $home = $pages->get(1);
       $p = new \ProcessWire\Page();
       $p->template = $tpl;
       $p->parent   = $home;
       $p->name     = 'account';
       $p->title    = 'Account';
       $p->addStatus(\ProcessWire\Page::statusHidden);
       try { $p->save(); } catch (\Throwable $e) { /* ignore */ }
     } else {
       if ((string)$account->template !== 'spl_account') {
         $account->setAndSave('template', $tpl);
       }
     }
   }
      
  /**
   * Get names of product templates from SPL configuration.
   *
   * @return array
   */
  private function getProductTemplateNames(): array {
    $cfg  = (array) $this->modules->getConfig('StripePaymentLinks');
    $raw  = (array) ($cfg['productTemplateNames'] ?? []);
    $san  = $this->wire('sanitizer');
    return array_values(array_unique(array_filter(array_map([$san, 'name'], $raw))));
  }

  /**
   * Returns normalized purchases: one row per (purchase × product).
   *
   * @param User $user
   * @return array
   */
   /*
  public function getPurchasesData(User $user): array {
    $pages = $this->wire('pages');
    $now   = time();

    // Collect all product IDs → load once
    $pids = [];
    foreach ($user->spl_purchases as $item) {
      $pids = array_merge($pids, array_map('intval', (array) $item->meta('product_ids')));
    }
    $pids = array_values(array_unique(array_filter($pids)));
    $byId = [];
    if ($pids) {
      foreach ($pages->find('id=' . implode('|', $pids) . ', include=all') as $p) $byId[(int)$p->id] = $p;
    }

    $rows = [];
    foreach ($user->spl_purchases as $item) {
      $ts   = (int) $item->created;
      $date = $ts ? date('Y-m-d H:i', $ts) : '';
      $map  = (array) $item->meta('period_end_map');
      foreach ((array) $item->meta('product_ids') as $pidRaw) {
        $pid = (int) $pidRaw;
        $p   = $byId[$pid] ?? null;
        if (!$p || !$p->id) continue;

        // Status
        $endRaw   = $map[(string)$pid] ?? null;
        $paused   = array_key_exists($pid . '_paused', $map);
        $canceled = array_key_exists($pid . '_canceled', $map);
        $statusKey = ''; $statusUntil = null; $isActive = null;
        if ($canceled) {
          $statusKey = 'canceled';
          if (is_numeric($endRaw)) $statusUntil = (int) $endRaw;
          $isActive = false;
        } elseif ($paused) {
          $statusKey = 'paused'; $isActive = false;
        } elseif (is_numeric($endRaw)) {
          $statusUntil = (int) $endRaw;
          $isActive = $statusUntil >= $now;
          $statusKey = $isActive ? 'active_until' : 'expired_on';
        }

        // Category/Tag for tabs (configurable: template, field, parent)
        $category = (string) ($p->get('product_category') ?: $p->template->label ?: $p->template->name);

        // Thumb (auto: first available image field of type FieldtypeImage)
        $thumbUrl = $this->productThumbUrl($p);

        $rows[] = [
          'purchase_ts'   => $ts,
          'purchase_date' => $date,
          'product_id'    => (int) $p->id,
          'product_title' => (string) $p->title,
          'product_url'   => (bool) $p->get('requires_access') ? $p->httpUrl : '',
          'thumb_url'     => $thumbUrl,
          'category'      => $category,
          'status_key'    => $statusKey,
          'status_until'  => $statusUntil,
          'is_active'     => $isActive,
        ];
      }
    }

    // Newest first
    usort($rows, fn($a, $b) => $b['purchase_ts'] <=> $a['purchase_ts']);
    return $rows;
  }
*/

/** Returns normalized purchases: one row per (purchase × product). */
public function getPurchasesData(User $user): array {
  $pages = $this->wire('pages');
  $now   = time();

  // Collect all product IDs → load once
  $pids = [];
  foreach ($user->spl_purchases as $item) {
    $pids = array_merge($pids, array_map('intval', (array) $item->meta('product_ids')));
  }
  $pids = array_values(array_unique(array_filter($pids)));
  $byId = [];
  if ($pids) {
    foreach ($pages->find('id=' . implode('|', $pids) . ', include=all') as $p) $byId[(int)$p->id] = $p;
  }

  $rows = [];
  foreach ($user->spl_purchases as $item) {
    $ts   = (int) $item->created;
    $date = $ts ? date('Y-m-d H:i', $ts) : '';
    $map  = (array) $item->meta('period_end_map');

    foreach ((array) $item->meta('product_ids') as $pidRaw) {
      $pid = (int) $pidRaw;
      $p   = $byId[$pid] ?? null;
      if (!$p || !$p->id) continue;

      // Derive status/access
      $endRaw   = $map[(string)$pid] ?? null;
      $paused   = array_key_exists($pid . '_paused', $map);
      $canceled = array_key_exists($pid . '_canceled', $map);

      $statusKey   = '';
      $statusUntil = null;
      $isActive    = null;

      if ($canceled) {
        $statusKey = 'canceled';
        if (is_numeric($endRaw)) $statusUntil = (int) $endRaw;
        $isActive = false;
      } elseif ($paused) {
        $statusKey = 'paused';
        $isActive  = false;
      } elseif (is_numeric($endRaw)) {
        $statusUntil = (int) $endRaw;
        $isActive    = ($statusUntil >= $now);
        $statusKey   = $isActive ? 'active_until' : 'expired_on';
      } else {
        // No period end → treat as timeless access (one-time/lifetime)
        $statusKey = 'active';
        $isActive  = true;
      }

      // Category/label for tabs
      $category = (string)($p->get('product_category') ?: $p->template->label ?: $p->template->name);

      // First available image field (any name)
      $thumbUrl = '';
      foreach ($p->fields as $f) {
        if ($f->type instanceof \ProcessWire\FieldtypeImage) {
          $imgs = $p->get($f->name);
          if ($imgs && $imgs->count()) {
            $thumbUrl = $imgs->first()->size(800, 600)->url;
          }
          break;
        }
      }

      $rows[] = [
        'purchase_ts'   => $ts,
        'purchase_date' => $date,
        'product_id'    => (int) $p->id,
        'product_title' => (string) $p->title,
        'product_url'   => (bool) $p->get('requires_access') ? $p->httpUrl : '',
        'thumb_url'     => $thumbUrl,
        'category'      => $category,
        'status_key'    => $statusKey,    // 'active'|'active_until'|'expired_on'|'paused'|'canceled'
        'status_until'  => $statusUntil,  // unix ts or null
        'is_active'     => $isActive,     // true|false
      ];
    }
  }

  usort($rows, fn($a,$b)=> $b['purchase_ts'] <=> $a['purchase_ts']);
  return $rows;
}

  /* ========================= Rendering ========================= */

  /**
   * Public method so the template can call it.
   *
   * @param string $view
   * @return string
   */
  public function renderAccount(string $view = 'grid'): string {
    $user = $this->wire('user');

    // Not logged in → NO redirect, open modal instead
    if (!$user->isLoggedin()) {
      $session = $this->wire('session');

      // Mark for texts / return URL
      $session->set('pl_open_login', 1); // JS should open modal
      $session->set('pl_intended_url', $this->wire('page')->httpUrl); // target / texts

      // Return a slim wrapper — content will come after login
      return $this->wrapContainer('<div class="my-5"></div>');
    }

    // explicit view parameter OR ?view=table override
    $viewParam = $this->wire('input')->get->text('view');
    if ($viewParam) $view = $viewParam;

    // choose rendering mode
    switch ($view) {
      case 'table':
        $content = $this->renderPurchasesTable($user);
        break;
      case 'grid':
        $content = $this->renderPurchasesGrid($user);
        break;
      case 'grid-all':
        $content = $this->renderPurchasesGridAll($user);
        break;
      default:
        // fallback → future views can be added easily here
        $content = $this->renderPurchasesGrid($user);
        break;
    }

    $content = '<div class="row g-3">' . $content . '</div>';
    return $this->wrapContainer($content . $this->modalProfileEdit($user));
  }

  /**
   * Render the "Edit my data" button.
   *
   * @param array $opts
   * @return string
   */
  public function renderEditButton(array $opts = []): string {
    $label  = $opts['label'] ?? $this->tLocal('button.edit');
    $class  = trim('btn btn-primary d-flex align-items-center ' . ($opts['class'] ?? ''));
    $idAttr = isset($opts['id']) ? ' id="' . htmlspecialchars((string)$opts['id'], ENT_QUOTES) . '"' : '';

    return '<button type="button"' . $idAttr . ' class="' . htmlspecialchars($class, ENT_QUOTES) . '"'
         . ' data-bs-toggle="modal" data-bs-target="#profileModal">'
         . '<i class="bi bi-pencil fs-6 d-inline d-md-none"></i>'
         . '<span class="d-none d-md-inline ms-1">' . htmlspecialchars($label, ENT_QUOTES) . '</span>'
         . '</button>';
  }

  /**
   * Renders the top-right button group: view switcher + edit button.
   *
   * @param string $activeView
   * @return string
   */
  public function renderHeaderButtons(string $activeView = 'grid-all'): string {
    $urlBase = $this->wire('page')->url;
    $editBtn = $this->renderEditButton(['class' => 'btn btn-primary btn-sm']);
    $isGrid  = $activeView === 'grid-all';
    $isTable = $activeView === 'table';

    $icons = sprintf(
      '<div class="d-flex align-items-center gap-3 me-4">
        <a href="%1$s?view=grid-all" class="text-secondary %2$s" title="Grid view">
          <i class="bi bi-grid fs-5"></i>
        </a>
        <a href="%1$s?view=table" class="text-secondary %3$s" title="Table view">
          <i class="bi bi-list-ul fs-3"></i>
        </a>
      </div>',
      htmlspecialchars($urlBase, ENT_QUOTES),
      $isGrid ? 'text-primary' : '',
      $isTable ? 'text-primary' : ''
    );

    return '<div class="d-flex align-items-center">' . $icons . $editBtn . '</div>';
  }

  /* ========================= UI bits ========================= */

  /**
   * Wrap content in a Bootstrap container for layout.
   *
   * @param string $inner
   * @return string
   */
  private function wrapContainer(string $inner): string {
    return '<div class="container mb-5"><div class="row"><div class="col-lg-10 mx-auto">' . $inner . '</div></div></div>';
  }

  /** 
  * Return a product thumb URL from the first available image field (any name). 
  */
  private function productThumbUrl(\ProcessWire\Page $p, int $w = 800, int $h = 600): string {
    // 1) If you ever want to prefer specific field names, you can drop them here:
    $preferred = []; // e.g. ['hero', 'cover', 'images'] — leave empty to just scan all fields
  
    foreach ($preferred as $fname) {
      if ($p->hasField($fname)) {
        $imgs = $p->get($fname);
        if ($imgs instanceof \ProcessWire\Pageimages && $imgs->count()) {
          return $imgs->first()->size($w, $h)->url;
        }
      }
    }
  
    // 2) Fallback: scan every field on the page and pick the first image field with images
    foreach ($p->fields as $field) {
      if ($field->type instanceof \ProcessWire\FieldtypeImage) {
        $imgs = $p->get($field->name);
        if ($imgs instanceof \ProcessWire\Pageimages && $imgs->count()) {
          return $imgs->first()->size($w, $h)->url;
        }
      }
    }
    return '';
  }
  /**
   * Render purchased products as cards (one card per product).
   *
   * @param User $user
   * @param array $opts
   * @return string
   */
  public function renderPurchasesGrid(User $user, array $opts = []): string {
    $rows = $this->getPurchasesData($user);
    if (!$rows) return '<p>' . $this->tLocal('ui.table.no_purchases') . '</p>';
  
    // Keep: one-time purchases ("active") and subscriptions with active access ("active_until" + is_active=true).
    // Drop: paused, canceled, expired, anything else.
    $seen   = [];
    $usable = [];
    foreach ($rows as $r) {
      $keep =
        ($r['status_key'] === 'active') ||
        ($r['status_key'] === 'active_until' && $r['is_active'] === true);
  
      if (!$keep) continue;
  
      $pid = (int) $r['product_id'];
      if (isset($seen[$pid])) continue; // de-dupe per product
      $seen[$pid] = true;
  
      $usable[] = $r;
    }
    if (!$usable) return '<p>' . $this->tLocal('ui.table.no_purchases') . '</p>';
  
    // CSS once
    $css = '<style id="spl-card-overlay-css">
  .spl-card{position:relative;overflow:hidden;border:0}
  .spl-card .card-img-top{display:block;width:100%;height:auto}
  .spl-card .spl-grad{position:absolute;left:0;right:0;bottom:0;top:50%;
    background:linear-gradient(to top,rgba(0,0,0,.5) 0%,rgba(0,0,0,0) 100%)}
  .spl-card .spl-title{position:absolute;left:0;right:0;bottom:10px;padding:16px 18px;
    text-align:center;color:#fff;font-weight:700;text-shadow:0 1px 2px rgba(0,0,0,.6)}
  </style>';
  
    // Badge only for active_until (date). One-time "active" has no badge.
    $badge = function(array $r): string {
      if ($r['status_key'] === 'active_until' && !empty($r['status_until'])) {
        return '<span class="badge text-bg-success rounded-pill shadow-sm">'
             . $this->tLocalFmt('status.active_until', ['{date}' => date('Y-m-d', (int)$r['status_until'])])
             . '</span>';
      }
      return '';
    };
  
    $out = $css;
    foreach ($usable as $r) {
      $title  = htmlspecialchars($r['product_title'], ENT_QUOTES);
      $imgTag = $r['thumb_url'] ? '<img class="card-img-top" src="' . htmlspecialchars($r['thumb_url'], ENT_QUOTES) . '" alt="">' : '';
      $anchor = $r['product_url'] ? '<a href="' . htmlspecialchars($r['product_url'], ENT_QUOTES) . '" class="stretched-link"></a>' : '';
  
      $out .= '
        <div class="col-12 col-sm-6 col-lg-4">
          <div class="card spl-card shadow-sm">
            <div class="position-relative">
              ' . $imgTag . '
              <div class="spl-grad"></div>
              <div class="spl-title"><h3 class="m-0">' . $title . '</h3></div>
              <div class="position-absolute top-0 end-0 m-2">' . $badge($r) . '</div>
            </div>
            ' . $anchor . '
          </div>
        </div>';
    }
    return $out;
  }
  
  /**
   * Grid: purchased products on top (using renderPurchasesGrid), below "not yet purchased" in gray.
   *
   * @param User $user
   * @return string
   */
  public function renderPurchasesGridAll(User $user): string {
    // 1) active/purchased cards
    $ownedHtml = $this->renderPurchasesGrid($user);
  
    // 2) collect active-owned product IDs
    $rows = $this->getPurchasesData($user);
    $ownedActiveIds = [];
    foreach ($rows as $r) {
      if ($r['status_key'] === 'active' ||
         ($r['status_key'] === 'active_until' && $r['is_active'] === true)) {
        $ownedActiveIds[(int) $r['product_id']] = true;
      }
    }
  
    // 3) find all gated products that are NOT actively owned
    $all = $this->findAccessProducts();
    $unowned = [];
    foreach ($all as $p) {
      if (!isset($ownedActiveIds[(int) $p->id])) $unowned[] = $p;
    }
    if (!$unowned) return $ownedHtml;
  
    // 4) CSS only for gray overlay
    $css = '<style id="spl-gray-cards">
  .spl-card.spl-gray .card-img-top{filter:grayscale(100%);opacity:.9}
  .spl-card.spl-gray:hover .card-img-top{filter:none;opacity:1}
  </style>';
  
    $out = $ownedHtml . $css;
  
    // 5) render unowned cards in gray
    foreach ($unowned as $p) {
      $title = htmlspecialchars((string) $p->title, ENT_QUOTES);
      $thumb = $this->productThumbUrl($p);
      $img   = $thumb ? '<img class="card-img-top" src="' . htmlspecialchars($thumb, ENT_QUOTES) . '" alt="">' : '';
  
      $out .= '
        <div class="col-12 col-sm-6 col-lg-4">
          <div class="card spl-card spl-gray shadow-sm">
            <div class="position-relative">
              ' . $img . '
              <div class="spl-grad"></div>
              <div class="spl-title"><h3 class="m-0">' . $title . '</h3></div>
            </div>
            <a href="' . htmlspecialchars($p->httpUrl, ENT_QUOTES) . '" class="stretched-link"></a>
          </div>
        </div>';
    }
  
    return $out;
  }  

  /**
   * Render purchases as a compact table.
   *
   * @param User $user
   * @return string
   */
  private function renderPurchasesTable(User $user): string {
    $rows = $this->getPurchasesData($user);
    if (!$rows) {
      return '<p>' . $this->tLocal('ui.table.no_purchases') . '</p>';
    }

    $out  = '<h3 class="mb-3">' . $this->tLocal('ui.purchases.title') . '</h3>';
    $out .= '<div class="table-responsive"><table class="table table-sm align-middle">';
    $out .= '<thead><tr>'
          . '<th style="width:180px;">' . $this->tLocal('ui.table.head.date') . '</th>'
          . '<th>' . $this->tLocal('ui.table.head.product') . '</th>'
          . '<th style="width:220px;">' . $this->tLocal('ui.table.head.status') . '</th>'
          . '</tr></thead><tbody>';

    foreach ($rows as $r) {
      $date = htmlspecialchars($r['purchase_date'], ENT_QUOTES);
      $prod = $r['product_url']
        ? '<a href="' . htmlspecialchars($r['product_url'], ENT_QUOTES) . '">' . htmlspecialchars($r['product_title'], ENT_QUOTES) . '</a>'
        : htmlspecialchars($r['product_title'], ENT_QUOTES);

      $status = '';
      if ($r['status_key'] === 'active_until' && $r['status_until'])
        $status = $this->tLocalFmt('status.active_until', ['{date}' => date('Y-m-d', $r['status_until'])]);
      elseif ($r['status_key'] === 'expired_on' && $r['status_until'])
        $status = $this->tLocalFmt('status.expired_on', ['{date}' => date('Y-m-d', $r['status_until'])]);
      elseif ($r['status_key'])
        $status = $this->tLocal('status.' . $r['status_key']);

      $out .= '<tr>'
            . '<td style="white-space:nowrap;">' . $date . '</td>'
            . '<td>' . $prod . '</td>'
            . '<td>' . htmlspecialchars($status, ENT_QUOTES) . '</td>'
            . '</tr>';
    }

    $out .= '</tbody></table></div>';
    return $out;
  }

  /**
   * Resolve absolute paths to SPL UI bits (ModalRenderer + modal view).
   *
   * @return array
   */
  private function splUiPaths(): array {
    $file = $this->modules->getModuleFile('StripePaymentLinks'); // path to SPL module file
    $base = dirname($file);
    return [
      'renderer' => $base . '/includes/ui/ModalRenderer.php',
      'view'     => $base . '/includes/views/modal.php',
    ];
  }

  /**
   * Renders the profile edit modal using SPL's renderer/view, but local texts.
   *
   * @param User|null $user
   * @return string
   */
  public function modalProfileEdit(?\ProcessWire\User $user = null): string {
    $user ??= $this->wire('user');
    $paths = $this->splUiPaths();

    if (is_file($paths['renderer'])) require_once $paths['renderer'];
    if (!class_exists('\ProcessWire\ModalRenderer')) return '';

    $spl = $this->spl();

    $ui = new \ProcessWire\ModalRenderer(
      is_file($paths['view']) ? $paths['view'] : null,
      function () use ($spl) { return $spl->wire('session')->CSRF->renderInput(); }
    );

    $h         = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $title     = $this->tLocal('profile.title');
    $introHtml = '<p><b>' . htmlspecialchars($this->tLocal('profile.intro'), ENT_QUOTES, 'UTF-8') . '</b></p>';
    $btnSave   = $this->tLocal('profile.save');
    $btnCancel = $this->tLocal('profile.cancel');

    // new i18n keys (Labels)
    $labelName    = $this->tLocal('label.name');
    $labelPass    = $this->tLocal('label.password');
    $labelPass2   = $this->tLocal('label.password_confirm');
    $prefillTitle = $user ? (string) $user->title : '';

    $modal = [
      'id'    => 'profileModal',
      'title' => $h($title),
      'form'  => [
        'action' => $this->spl()->apiUrl(),
        'op'     => 'profile_update',
        'hidden' => [
          // use return_url here instead of depending on other behavior
          'return_url' => $this->wire('page')->httpUrl,
        ],
        'bodyIntro' => $introHtml,
        'fields'    => [
          ['type' => 'text', 'name' => 'title', 'label' => $labelName, 'value' => $prefillTitle, 'attrs' => ['autocomplete' => 'name']],
          ['type' => 'password', 'name' => 'password', 'label' => $labelPass, 'attrs' => ['autocomplete' => 'new-password']],
          ['type' => 'password', 'name' => 'password_confirm', 'label' => $labelPass2, 'attrs' => ['autocomplete' => 'new-password']],
        ],
        'submitText'  => $btnSave,
        'cancelText'  => $btnCancel,
        'footerClass' => 'modal-footer bg-light-subtle',
      ],
    ];

    return $ui->render($modal);
  }

  /**
   * Render a login / account / logout link depending on user state and location.
   *
   * @param array $opts
   * @return string
   */
  public function renderLoginLink(array $opts = []): string {
    $user      = $this->wire('user');
    $page      = $this->wire('page');
    $session   = $this->wire('session');
    $config    = $this->wire('config');
    $class     = trim((string) ($opts['class'] ?? ''));
    $isAccount = (strpos((string) $page->url, '/account/') === 0);

    // --- not logged in: Login link (open modal) ---
    if (!$user->isLoggedin()) {
      $label = $opts['label'] ?? $this->tLocal('link.login');

      // SPL handles redirect after successful login.
      $session->set('pl_intended_url', $config->urls->root . 'account/');

      $onclick = "var m=document.getElementById('loginModal');"
               . "if(m&&window.bootstrap){bootstrap.Modal.getOrCreateInstance(m).show();return false;}"
               . "return false;";

      return sprintf(
        '<a href="#" class="%s" onclick="%s">%s</a>',
        htmlspecialchars($class, ENT_QUOTES),
        htmlspecialchars($onclick, ENT_QUOTES),
        htmlspecialchars($label, ENT_QUOTES)
      );
    }

    // --- logged in + on /account/ → Logout link ---
    if ($isAccount) {
      $href  = $page->url . '?spl_logout=1';
      $label = $opts['label'] ?? $this->tLocal('link.logout');
      return sprintf(
        '<a href="%s" class="%s">%s</a>',
        htmlspecialchars($href, ENT_QUOTES),
        htmlspecialchars($class, ENT_QUOTES),
        htmlspecialchars($label, ENT_QUOTES)
      );
    }

    // --- logged in elsewhere → My Account link ---
    $label = $opts['label'] ?? $this->tLocal('link.account');
    return sprintf(
      '<a href="%saccount/" class="%s">%s</a>',
      htmlspecialchars($config->urls->root, ENT_QUOTES),
      htmlspecialchars($class, ENT_QUOTES),
      htmlspecialchars($label, ENT_QUOTES)
    );
  }

  /**
   * Helper to return JSON responses.
   *
   * @param array $a
   * @param int $status
   * @return string
   */
  private function j(array $a, int $status = 200): string {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    return json_encode($a, JSON_UNESCAPED_UNICODE);
  }

  /**
   * Handle profile update requests routed through /stripepaymentlinks/api.
   *
   * @param \ProcessWire\HookEvent $e
   * @return void
   */
  private function handleProfileUpdate(\ProcessWire\HookEvent $e): void {
    $input = $this->wire('input');
    if (!$input->requestMethod('POST')) return;
    $op = (string) ($input->post->op ?? $input->post->action ?? '');
    if ($op !== 'profile_update') return;

    $e->replace = true;

    $session = $this->wire('session');
    $users   = $this->wire('users');
    $san     = $this->wire('sanitizer');
    $u       = $this->wire('user');

    if (!$session->CSRF->hasValidToken()) {
      $e->return = $this->j(['ok' => false, 'error' => $this->tLocal('api.csrf_invalid')], 400); return;
    }
    if (!$u->isLoggedin()) {
      $e->return = $this->j(['ok' => false, 'error' => $this->tLocal('api.not_signed_in')], 401); return;
    }

    $newTitle = trim((string) $input->post->text('title'));
    $pass1    = (string) $input->post->text('password');
    $pass2    = (string) $input->post->text('password_confirm');

    if ($pass1 !== '' || $pass2 !== '') {
      if (strlen($pass1) < 8) { $e->return = $this->j(['ok' => false, 'error' => $this->spl()->t('api.password.too_short')]); return; }
      if ($pass1 !== $pass2)  { $e->return = $this->j(['ok' => false, 'error' => $this->spl()->t('api.password.mismatch')]); return; }
    }

    try {
      $u->of(false);
      if ($newTitle !== '' && $newTitle !== (string) $u->title) $u->title = $newTitle;
      if ($pass1 !== '') $u->pass = $pass1;
      $this->wire('users')->save($u, ['quiet' => true]);

      $ret = $this->wire('sanitizer')->url((string) $this->wire('input')->post->return_url) ?: $this->wire('page')->httpUrl;
      $hostOk = true;
      try {
        $ru = parse_url($ret);
        $hostOk = empty($ru['host']) || $ru['host'] === $this->wire('config')->httpHost;
      } catch (\Throwable $ex) { $hostOk = false; }
      if (!$hostOk) $ret = $this->wire('page')->httpUrl;

      $e->return = $this->j(['ok' => true, 'message' => $this->tLocal('api.profile_updated'), 'redirect' => $ret]);
    } catch (\Throwable $ex) {
      $e->return = $this->j(['ok' => false, 'error' => $this->spl()->t('api.server_error')], 500);
    }
  }
}
