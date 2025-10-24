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

  public static function getModuleInfo(): array {
	return [
	  'title'       => 'StripePaymentLinks Customer Portal',
	  'version'     => '0.1.0',
	  'summary'     => 'Customer overview at /account using a dedicated template (spl_account).',
	  'author'      => 'frameless Media',
	  'autoload'    => true,
	  'singular'    => true,
	  'requires'    => ['ProcessWire>=3.0.210', 'StripePaymentLinks'],
	  'icon'        => 'user-circle',
	];
  }


  /* ========================= Lifecycle ========================= */

  /** Ensure the template/page exist also after module refresh/upgrades. */
  public function init(): void {
	$this->ensureAccountTemplateAndPage();

    $this->addHook('/stripepaymentlinks/api', function(\ProcessWire\HookEvent $e){
		$this->handleProfileUpdate($e);
	  });
	  
	  $this->addHookAfter('StripePaymentLinks::t', function($e){
		$page = wire('page');
		if (!$page || (string)$page->path !== '/account/') return;
		$key = (string) $e->arguments(0);
		if ($key === 'modal.loginrequired.title') { $e->return = $this->tLocal('modal.loginrequired.title'); return; }
		if ($key === 'modal.loginrequired.body')  { $e->return = $this->tLocal('modal.loginrequired.body');  return; }
	  });
	  
		$this->addHookAfter('Page::render', function(\ProcessWire\HookEvent $e) {
			$session = $this->wire('session');
			$user    = $this->wire('user');
			
			if ($user->isLoggedin()) {$session->remove('pl_open_login');return;}
			if (!$session->get('pl_open_login')) return;
			
			$html = (string)$e->return;
			$session->remove('pl_open_login');
		
			if (strpos($html, 'id="loginModal"') !== false) {
				$js = <<<JS
				<script>document.addEventListener('DOMContentLoaded',function(){var el = document.getElementById('loginModal');if(el && window.bootstrap){var m = bootstrap.Modal.getOrCreateInstance(el);m.show();}});</script>
				JS;
				$html = preg_replace('~</body>~i', $js . '</body>', $html, 1);
				$e->return = $html;
			}
		});
  }

  /** Create template, template file and page on install. */
  public function ___install(): void {
	$this->ensureAccountTemplateAndPage(true);
  }

  /** Keep things intact on upgrade as well. */
  public function ___upgrade($from, $to): void {
	$this->ensureAccountTemplateAndPage(true);
  }

  /** Optional tidy-up on uninstall: remove page; drop template if unused. */
  public function ___uninstall(): void {
	$pages     = $this->wire('pages');
	$templates = $this->wire('templates');

	if ($p = $pages->get('/account/')) {
	  try { $pages->delete($p, true); } catch (\Throwable $e) {}
	}
	if ($tpl = $templates->get('spl_account')) {
	  // delete only if no pages use it anymore
	  if (!$pages->count("template=spl_account")) {
		try { $templates->delete($tpl); } catch (\Throwable $e) {}
	  }
	}
	// Die physische Datei lassen wir unangetastet (kann angepasst worden sein).
  }

  /** Helper: main StripePaymentLinks module */
	private function spl(): \ProcessWire\StripePaymentLinks {
	  /** @var \ProcessWire\StripePaymentLinks $mod */
	  $mod = $this->modules->get('StripePaymentLinks');
	  return $mod;
	}
	
  /** Local UI texts (multilanguage via $this->_()) */
	private function i18n(): array {
	  return [
		// Headings / UI
		'ui.purchases.title'        => $this->_('Your purchases'),
		'ui.table.no_purchases'     => $this->_('No purchases found.'),
		'ui.table.head.date'        => $this->_('Date'),
		'ui.table.head.product'     => $this->_('Product'),
		'ui.table.head.status'      => $this->_('Status'),
	
		// Button
		'button.edit'               => $this->_('Edit my data'),
	
		// Profile modal
		'profile.title'             => $this->_('Edit my data'),
		'profile.intro'             => $this->_('Update your account information below.'),
		'profile.save'              => $this->_('Save'),
		'profile.cancel'            => $this->_('Cancel'),
		'label.name'                => $this->_('Full name'),
		'label.password'            => $this->_('Password'),
		'label.password_confirm'    => $this->_('Confirm password'),
	
		// Login-required text (used to override SPL’s t())
		'modal.loginrequired.title' => $this->_('Customer Login'),
		'modal.loginrequired.body'  => $this->_('Please sign in to view your purchases.'),
	
		// Status strings (with placeholders)
		'status.active_until'       => $this->_('Active until {date}'),
		'status.expired_on'         => $this->_('Expired on {date}'),
		'status.paused'             => $this->_('Paused'),
		'status.canceled'           => $this->_('Canceled'),
		'status.canceled_until'     => $this->_('Canceled (until {date})'),
	
		// API responses (local)
		'api.csrf_invalid'          => $this->_('Invalid CSRF'),
		'api.not_signed_in'         => $this->_('Not signed in.'),
		'api.profile_updated'       => $this->_('Profile updated.'),
	  ];
	}
	
	private function tLocal(string $key): string {
	  static $L = null;
	  if ($L === null) $L = $this->i18n();
	  return $L[$key] ?? '';
	}
	
	/** Tiny formatter: replaces {token} with given values */
	private function tLocalFmt(string $key, array $repl): string {
	  $txt = $this->tLocal($key);
	  return strtr($txt, $repl);
	}
	
  /** Create/verify template + file + page. */
  private function ensureAccountTemplateAndPage(bool $writeFile = false): void {
	$templates   = $this->wire('templates');
	$fieldgroups = $this->wire('fieldgroups'); // wichtig: eigenes API-Objekt
	$pages       = $this->wire('pages');
	$config      = $this->wire('config');
  
	// 1) Fieldgroup: holen oder neu anlegen
	/** @var \ProcessWire\Fieldgroup|null $fg */
	$fg = $fieldgroups->get('fg_spl_account');
	if (!$fg || !$fg->id) {
	  $fg = new \ProcessWire\Fieldgroup();
	  $fg->name = 'fg_spl_account';
	  $fieldgroups->save($fg);
	}
  
	// 2) Template: holen oder neu anlegen und Fieldgroup zuweisen
	/** @var \ProcessWire\Template|null $tpl */
	$tpl = $templates->get('spl_account');
	if (!$tpl || !$tpl->id) {
	  $tpl = new \ProcessWire\Template();
	  $tpl->name       = 'spl_account';
	  $tpl->fieldgroup = $fg;
	  $tpl->noChildren = 1;
	  $templates->save($tpl);
	} else {
	  // sicherstellen, dass die richtige Fieldgroup dran hängt
	  if (!$tpl->fieldgroup || $tpl->fieldgroup->id !== $fg->id) {
		$tpl->fieldgroup = $fg;
		$templates->save($tpl);
	  }
	}
  
	// 3) Template-Datei /site/templates/spl_account.php anlegen (nur wenn fehlt)
	$tplFile = rtrim($config->paths->templates, '/\\') . DIRECTORY_SEPARATOR . 'spl_account.php';
	if ($writeFile || !is_file($tplFile)) {
	  $code = <<<'PHP'
  <?php namespace ProcessWire;
  /** @var \ProcessWire\Modules $modules */
  $portal = $modules->get('StripePlCustomerPortal');
  $content = $portal->renderAccount();
  PHP;
	  if (is_dir($config->paths->templates)) {
		@file_put_contents($tplFile, $code, LOCK_EX);
		@chmod($tplFile, 0660);
	  }
	}
  
	// 4) Seite /account anlegen, falls sie nicht existiert
	$account = $pages->get('/account/');
	if (!$account || !$account->id) {
	  $home = $pages->get(1); // Root
	  $p = new \ProcessWire\Page();
	  $p->template = $tpl;
	  $p->parent   = $home;
	  $p->name     = 'account';
	  $p->title    = 'Account';
	  try { $p->save(); } catch (\Throwable $e) { /* ignore */ }
	} else {
	  // sicherstellen, dass Seite das richtige Template hat
	  if ((string)$account->template !== 'spl_account') {
		$account->setAndSave('template', $tpl);
	  }
	}
  }
  
  /* ========================= Rendering ========================= */

  /** Public so the template can call it. */
  public function renderAccount(): string {
	$user = $this->wire('user');
  
  if(!$user->isLoggedin()){
		$session = $this->wire('session');
		$config  = $this->wire('config');
	
		// Nach Login wieder zu /account/
		$session->set('pl_intended_url', $this->wire('page')->httpUrl);
	
		// Login-Modal auf der nächsten Seite automatisch öffnen
		$session->set('pl_open_login', 1);
	
		// Sichere Default-Ziel-URL (Home) ohne Pages-API
		$to = (string)($config->urls->httpRoot ?? $config->urls->root);
	
		// Same-origin-Referrer bevorzugen
		$ref = isset($_SERVER['HTTP_REFERER']) ? (string) $_SERVER['HTTP_REFERER'] : '';
		if($ref !== ''){
		  try {
			$ru = parse_url($ref);
			if(!empty($ru['host']) && $ru['host'] === $config->httpHost) {
			  $to = $ref;
			}
		  } catch(\Throwable $ex) { /* ignore → fallback bleibt $to */ }
		}
	
		$session->redirect($to, false);
		return '';
	  }
  
  		return $this->wrapContainer(
		$this->renderPurchasesTable($user) .
		'<div class="mt-4"><button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#profileModal">'
		. $this->tLocal('button.edit') .
		'</button></div>' .
		$this->modalProfileEdit($user)
	  );
  }
  
  /* ========================= UI bits ========================= */

  private function wrapContainer(string $inner): string {
	return '<div class="container my-4"><div class="row"><div class="col-lg-10 mx-auto">' . $inner . '</div></div></div>';
  }

private function renderPurchasesTable(User $user): string {
	if (!$user->hasField('spl_purchases') || !$user->spl_purchases->count()) {
  	return '<p>' . $this->tLocal('ui.table.no_purchases') . '</p>';
	}
  
	$pages = $this->wire('pages');
	$now   = time();
  
	// 1) Alle Produkt-IDs aus allen Käufen einsammeln und einmal laden
	$allPids = [];
	foreach ($user->spl_purchases as $item) {
	  $allPids = array_merge($allPids, array_map('intval', (array)$item->meta('product_ids')));
	}
	$allPids = array_values(array_unique(array_filter($allPids)));
	$pagesById = [];
	if ($allPids) {
	  /** @var \ProcessWire\PageArray $found */
	  $found = $pages->find('id=' . implode('|', $allPids));
	  foreach ($found as $p) $pagesById[(int)$p->id] = $p;
	}
  
	// 2) Zeilen auf Basis der Metas bauen (eine Zeile pro Produkt in einem Kauf)
	$rows = [];
	foreach ($user->spl_purchases as $item) {
	  /** @var Page $item */
	  $ts = (int) $item->created;
	  $date = $ts ? date('Y-m-d H:i', $ts) : '—';
  
	  $pids = array_map('intval', (array)$item->meta('product_ids'));
	  if (!$pids) {
		// Fallback-Zeile, falls keine Produktzuordnung vorhanden
		$rows[] = ['ts' => $ts, 'date' => $date, 'product' => '—', 'status' => ''];
		continue;
	  }
  
	  $map = (array)$item->meta('period_end_map');
  
	  foreach ($pids as $pid) {
		$p = $pagesById[$pid] ?? null;
  	  	if (!$p || !$p->id) continue;
			
		// Produktname (+ Link, wenn Onlineprodukt)
		if ($p && $p->id) {
		  $title = $this->sanitizer->entities((string)$p->title);
		  $isOnline = (bool)$p->get('requires_access');
		  $productHtml = $isOnline
			? '<a href="' . $this->sanitizer->entities($p->httpUrl) . '">' . $title . '</a>'
			: $title;
		} else {
		  $productHtml = 'Product #' . (int)$pid;
		}
  
		// Abo-Status aus period_end_map (+ Flags) ableiten
		$endRaw   = $map[(string)$pid] ?? null;
		$paused   = array_key_exists($pid . '_paused',   $map);
		$canceled = array_key_exists($pid . '_canceled', $map);
  
  		$status = '';
		if ($canceled) {
		  $status = is_numeric($endRaw)
			? $this->tLocalFmt('status.canceled_until', ['{date}' => date('Y-m-d', (int)$endRaw)])
			: $this->tLocal('status.canceled');
		} elseif ($paused) {
		  $status = $this->tLocal('status.paused');
		} elseif (is_numeric($endRaw)) {
		  $end = (int)$endRaw;
		  $status = ($end >= $now)
			? $this->tLocalFmt('status.active_until', ['{date}' => date('Y-m-d', $end)])
			: $this->tLocalFmt('status.expired_on',   ['{date}' => date('Y-m-d', $end)]);
		}
  
		$rows[] = ['ts' => $ts, 'date' => $date, 'product' => $productHtml, 'status' => $status];
	  }
	}
  
	// Neueste zuerst
	usort($rows, fn($a,$b) => $b['ts'] <=> $a['ts']);
  
	// 3) HTML
$out  = '<h2 class="h4 mb-3">' . $this->tLocal('ui.purchases.title') . '</h2>';
	$out .= '<div class="table-responsive"><table class="table table-sm align-middle">';
	$out .= '<thead><tr>'
		 .  '<th>' . $this->tLocal('ui.table.head.date')    . '</th>'
		 .  '<th>' . $this->tLocal('ui.table.head.product') . '</th>'
		 .  '<th>' . $this->tLocal('ui.table.head.status')  . '</th>'
		 .  '</tr></thead><tbody>';
  
	foreach ($rows as $r) {
	  $out .= '<tr>'
			. '<td style="white-space:nowrap;">' . $r['date'] . '</td>'
			. '<td>' . $r['product'] . '</td>'
			. '<td>' . $this->sanitizer->entities($r['status']) . '</td>'
			. '</tr>';
	}
  
	$out .= '</tbody></table></div>';
	return $out;
  }

  
  /** Minimal placeholder fill like SPL: supports {firstname} and {email} */
  private function fillPlaceholdersLocal(string $text, ?\ProcessWire\User $u = null): string {
	  $withTokens = strtr($text, ['{firstname}' => '%%FIRSTNAME%%', '{email}' => '%%EMAIL%%']);
	  $escaped    = htmlspecialchars($withTokens, ENT_QUOTES, 'UTF-8');
  
	  $firstname = $u ? (trim((string)$u->title) ?: (strpos($u->email, '@') !== false ? substr($u->email, 0, strpos($u->email, '@')) : $u->email)) : '';
	  $email     = $u ? (string)$u->email : '';
	  $fnEsc     = htmlspecialchars($firstname, ENT_QUOTES, 'UTF-8');
	  $emEsc     = htmlspecialchars($email,     ENT_QUOTES, 'UTF-8');
  
	  $out = strtr($escaped, [
		  '%%FIRSTNAME%%' => ($fnEsc !== '' ? '<b>'.$fnEsc.'</b>' : ''),
		  '%%EMAIL%%'     => ($emEsc !== '' ? '<b>'.$emEsc.'</b>' : ''),
	  ]);
	  return '<p>'.$out.'</p>';
  }
  
/** Resolve absolute paths to SPL UI bits (ModalRenderer + modal view) */
  private function splUiPaths(): array {
	  $file = $this->modules->getModuleFile('StripePaymentLinks'); // path to SPL module file
	  $base = dirname($file);
	  return [
		  'renderer' => $base . '/includes/ui/ModalRenderer.php',
		  'view'     => $base . '/includes/views/modal.php',
	  ];
  }
  
  
	/** Renders the profile edit modal using SPL's renderer/view, but local texts. */
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
  	$introHtml = $this->fillPlaceholdersLocal($this->tLocal('profile.intro'), $user);
  	$btnSave   = $this->tLocal('profile.save');
  	$btnCancel = $this->tLocal('profile.cancel');
	
  	// neue i18n keys (Labels)
  	$labelName   = $this->tLocal('label.name');
  	$labelPass   = $this->tLocal('label.password');
  	$labelPass2  = $this->tLocal('label.password_confirm');
  	$prefillTitle = $user ? (string)$user->title : '';
	
	$modal = [
		'id'    => 'profileModal',
		'title' => $h($title),
		'form'  => [
		  'action' => $this->spl()->apiUrl(),
		  'op'     => 'profile_update',
		  'hidden' => [                           // <— HIER statt 'return_url' direkt
			'return_url' => $this->wire('page')->httpUrl,
		  ],
		  'bodyIntro' => $introHtml,
		  'fields'    => [
			['type'=>'text','name'=>'title','label'=>$labelName,'value'=>$prefillTitle,'attrs'=>['autocomplete'=>'name']],
			['type'=>'password','name'=>'password','label'=>$labelPass,'attrs'=>['autocomplete'=>'new-password']],
			['type'=>'password','name'=>'password_confirm','label'=>$labelPass2,'attrs'=>['autocomplete'=>'new-password']],
		  ],
		  'submitText'  => $btnSave,
		  'cancelText'  => $btnCancel,
		  'footerClass' => 'modal-footer bg-light-subtle',
		],
	  ];
	
  	return $ui->render($modal);
	}
	
	private function j(array $a, int $status = 200): string {
	  http_response_code($status);
	  header('Content-Type: application/json; charset=utf-8');
	  return json_encode($a, JSON_UNESCAPED_UNICODE);
	}
private function handleProfileUpdate(\ProcessWire\HookEvent $e): void {
	  $input = $this->wire('input');
	  if(!$input->requestMethod('POST')) return;
	  $op = (string) ($input->post->op ?? $input->post->action ?? '');
	  if($op !== 'profile_update') return;
	
	  $e->replace = true;
	
	  $session = $this->wire('session');
	  $users   = $this->wire('users');
	  $san     = $this->wire('sanitizer');
	  $u       = $this->wire('user');
	
	  if(!$session->CSRF->hasValidToken()) {
		$e->return = $this->j(['ok'=>false,'error'=>$this->tLocal('api.csrf_invalid')], 400); return;
	  }
	  if(!$u->isLoggedin()) {
		$e->return = $this->j(['ok'=>false,'error'=>$this->tLocal('api.not_signed_in')], 401); return;
	  }
	
	  $newTitle = trim((string)$input->post->text('title'));
	  $pass1    = (string)$input->post->text('password');
	  $pass2    = (string)$input->post->text('password_confirm');
	
	  if($pass1 !== '' || $pass2 !== '') {
		if(strlen($pass1) < 8)  { $e->return = $this->j(['ok'=>false,'error'=>$this->spl()->t('api.password.too_short')]); return; }
		if($pass1 !== $pass2)   { $e->return = $this->j(['ok'=>false,'error'=>$this->spl()->t('api.password.mismatch')]); return; }
	  }
	
	  try {
		$u->of(false);
		if($newTitle !== '' && $newTitle !== (string)$u->title) $u->title = $newTitle;
		if($pass1 !== '') $u->pass = $pass1;
		$this->wire('users')->save($u, ['quiet'=>true]);
	
		$ret = $this->wire('sanitizer')->url((string)$this->wire('input')->post->return_url) ?: $this->wire('page')->httpUrl;
		$hostOk = true;
		try {
		  $ru = parse_url($ret);
		  $hostOk = empty($ru['host']) || $ru['host'] === $this->wire('config')->httpHost;
		} catch (\Throwable $ex) { $hostOk = false; }
		if(!$hostOk) $ret = $this->wire('page')->httpUrl;
	
		$e->return = $this->j(['ok'=>true,'message'=>$this->tLocal('api.profile_updated'),'redirect'=>$ret]);
	  } catch(\Throwable $ex) {
		$e->return = $this->j(['ok'=>false,'error'=>$this->spl()->t('api.server_error')], 500);
	  }
	}
}