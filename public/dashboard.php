<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
$u=current_user();
if(!$u){header('Location: /login.php');exit;}
$q=db()->prepare('SELECT id,email,role FROM users WHERE id=?');$q->execute([(int)$u['id']]);$fresh=$q->fetch();
if(!$fresh){$_SESSION=[];session_destroy();header('Location: /login.php');exit;}
$_SESSION['user']=['id'=>(int)$fresh['id'],'email'=>$fresh['email'],'role'=>$fresh['role']];$u=$_SESSION['user'];
$isStudent=$u['role']==='student';$view=$_GET['view']??'overview';
$studentViews=['overview','profile','questionnaire','matches','beds','reservations','payments','allocation','notifications','settings'];
$adminViews=['overview','students','eligibility','hostels','reservations','payments','contacts','reports','audit'];
$allowed=$isStudent?$studentViews:$adminViews;if(!in_array($view,$allowed,true))$view='overview';
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#17243A"><title><?=htmlspecialchars(ucfirst($view),ENT_QUOTES)?> | SmartHostel</title><link rel="stylesheet" href="/assets/style.css"><script src="/assets/theme.js"></script><script src="/assets/app.js" defer></script><script src="/assets/dashboard-enhancements.js" defer></script></head>
<body class="dashboard-page" data-role="<?=htmlspecialchars($u['role'],ENT_QUOTES)?>" data-view="<?=htmlspecialchars($view,ENT_QUOTES)?>">
<a class="skip-link" href="#dashboard-main">Skip to content</a>
<header class="dash-top"><button class="menu-toggle" type="button" aria-expanded="false" aria-controls="dash-nav">☰ <span class="sr-only">Open navigation</span></button><a class="brand" href="/dashboard.php"><span class="mark" aria-hidden="true">S</span> SmartHostel</a>
<div class="user-menu"><button class="theme-toggle" type="button" data-theme-toggle aria-pressed="false">Dark mode</button><div class="notification-control"><button id="notification-toggle" class="notification-toggle" type="button" aria-expanded="false" aria-controls="notification-popover" aria-label="Notifications"><svg aria-hidden="true" viewBox="0 0 24 24"><path d="M18 9a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg></button><span id="unread-count" class="unread-count" aria-label="Unread notifications">0</span><div id="notification-popover" class="notification-popover" hidden></div></div><span><?=htmlspecialchars($u['email'],ENT_QUOTES)?> <small><?=htmlspecialchars(str_replace('_',' ',$u['role']),ENT_QUOTES)?></small></span><button class="button secondary" id="logout" type="button">Sign out</button></div></header>
<div class="dash-layout"><aside id="dash-nav" class="sidebar"><p class="eyebrow"><?=$isStudent?'Student portal':'Administration'?></p>
<?php $items=$isStudent?['overview'=>'Dashboard','profile'=>'My profile','questionnaire'=>'Lifestyle questionnaire','matches'=>'Roommate matches','beds'=>'Browse available beds','reservations'=>'My reservations','payments'=>'Payment details','allocation'=>'My allocation','notifications'=>'Notifications','settings'=>'Account settings']:['overview'=>'Dashboard','students'=>'Student records','eligibility'=>'School-fee reviews','hostels'=>'Hostel and room management','reservations'=>'Reservations and allocations','payments'=>'Payment review','contacts'=>'Contact messages','reports'=>'Reports and CSV exports','audit'=>'Audit logs'];foreach($items as $key=>$label):?><a class="nav-link <?=$view===$key?'active':''?>" href="/dashboard.php?view=<?=$key?>"><?=$label?></a><?php endforeach;?>
<?php if($u['role']==='admin'):?><p class="permission-note">Payment and eligibility review actions are reserved for super administrators.</p><?php endif;?></aside>
<main id="dashboard-main" class="dash-content"><div class="dash-heading"><div><p class="eyebrow"><?=htmlspecialchars(str_replace('_',' ',$u['role']),ENT_QUOTES)?> workspace</p><h1><?=htmlspecialchars($items[$view]??'Dashboard',ENT_QUOTES)?></h1></div><p id="page-hint">Loading your latest information…</p></div><div id="notice" aria-live="off" aria-atomic="false"></div><div id="dashboard-view" aria-live="polite" aria-busy="true"><div class="card loading">Loading dashboard…</div></div></main></div>
<footer><a href="/">Public homepage</a><span>Payment evidence review is in development mode.</span></footer>
<dialog id="action-dialog" class="action-dialog" aria-labelledby="action-dialog-title"><form method="dialog" id="action-dialog-form"><h2 id="action-dialog-title">Confirm action</h2><p id="action-dialog-message"></p><div id="action-dialog-note-wrap" hidden><label for="action-dialog-note">Review note <span class="muted">(optional)</span></label><textarea id="action-dialog-note" rows="4" maxlength="500"></textarea></div><div class="dialog-actions"><button class="button secondary" value="cancel">Cancel</button><button id="action-dialog-confirm" class="button" value="confirm">Continue</button></div></form></dialog>
</body></html>
