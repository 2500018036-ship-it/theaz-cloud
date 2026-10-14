<?php

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */
$routes->get('/', 'Home::index');
$routes->get('health', 'HealthController::index');
$routes->get('ready', 'HealthController::ready');
$routes->post('language', 'LanguageController::switch');

$routes->group('', ['filter' => 'guest'], static function (RouteCollection $routes): void {
    $routes->get('login', 'AuthController::loginForm');
    $routes->post('login', 'AuthController::login');
    $routes->get('register', 'AuthController::registerForm');
    $routes->post('register', 'AuthController::register');
});

$routes->get('email/verify/(:segment)', 'AuthController::verifyEmail/$1');
$routes->post('email/verification/resend', 'AuthController::resendVerification');
$routes->post('logout', 'AuthController::logout', ['filter' => 'auth']);
$routes->get('dashboard', 'DashboardController::index', ['filter' => 'auth']);
$routes->get('s/(:alphanum)', 'DriveController::sharedDownload/$1');

$routes->group('settings', ['filter' => 'auth'], static function (RouteCollection $routes): void {
    $routes->get('/', 'SettingsController::index');
    $routes->post('profile', 'SettingsController::updateProfile');
    $routes->post('preferences', 'SettingsController::updatePreferences');
    $routes->post('password', 'SettingsController::changePassword');
    $routes->post('email', 'SettingsController::requestEmail');
    $routes->get('email/verify/(:segment)', 'SettingsController::verifyEmail/$1');
    $routes->post('avatar', 'SettingsController::updateAvatar');
    $routes->post('avatar/delete', 'SettingsController::deleteAvatar');
    $routes->get('avatar', 'SettingsController::avatar');
});

$routes->group('notifications', ['filter' => 'auth'], static function (RouteCollection $routes): void {
    $routes->get('summary', 'NotificationsController::summary');
    $routes->get('(:num)', 'NotificationsController::show/$1');
    $routes->post('(:num)/open', 'NotificationsController::open/$1');
    $routes->get('/', 'NotificationsController::index');
    $routes->post('read-all', 'NotificationsController::markAllRead');
    $routes->post('(:num)/read', 'NotificationsController::markRead/$1');
});

$routes->group('drive', ['filter' => 'auth'], static function (RouteCollection $routes): void {
    $routes->get('/', 'DriveController::index');
    $routes->get('recent', 'DriveController::recent');
    $routes->get('starred', 'DriveController::starred');
    $routes->get('trash', 'DriveController::trash');
    $routes->get('shared-with-me', 'DriveController::sharedWithMe');
    $routes->get('shared-by-me', 'DriveController::sharedByMe');
    $routes->get('activity', 'DriveController::activity');
    $routes->post('folders', 'DriveController::createFolder');
    $routes->post('upload', 'DriveController::upload');
    $routes->post('upload/init', 'DriveController::uploadInit');
    $routes->post('upload/chunk', 'DriveController::uploadChunk');
    $routes->post('upload/complete', 'DriveController::uploadComplete');
    $routes->post('upload/cancel', 'DriveController::uploadCancel');
    $routes->post('upload/batch/init', 'DriveController::uploadBatchInit');
    $routes->post('upload/batch/stage', 'DriveController::uploadBatchStage');
    $routes->post('upload/batch/complete', 'DriveController::uploadBatchComplete');
    $routes->post('upload/batch/cancel', 'DriveController::uploadBatchCancel');
    $routes->post('bulk', 'DriveController::bulk');
    $routes->post('bulk-download', 'DriveController::bulkDownload');
    $routes->get('items/(:num)/preview-info', 'DriveController::previewInfo/$1');
    $routes->get('items/(:num)/preview-text', 'DriveController::previewText/$1');
    $routes->get('items/(:num)/preview', 'DriveController::preview/$1');
    $routes->get('items/(:num)/download', 'DriveController::download/$1');
    $routes->get('items/(:num)/thumb', 'DriveController::thumbnail/$1');
    $routes->get('items/(:num)/versions', 'DriveController::versions/$1');
    $routes->post('items/(:num)/versions', 'DriveController::uploadVersion/$1');
    $routes->post('items/(:num)/versions/(:num)/restore', 'DriveController::restoreVersion/$1/$2');
    $routes->post('items/(:num)/rename', 'DriveController::rename/$1');
    $routes->post('items/(:num)/move', 'DriveController::move/$1');
    $routes->post('items/(:num)/copy', 'DriveController::copy/$1');
    $routes->post('items/(:num)/extract', 'DriveController::extract/$1');
    $routes->post('items/(:num)/delete', 'DriveController::delete/$1');
    $routes->post('items/(:num)/restore', 'DriveController::restore/$1');
    $routes->post('items/(:num)/destroy', 'DriveController::destroy/$1');
    $routes->post('items/(:num)/star', 'DriveController::star/$1');
    $routes->post('items/(:num)/unstar', 'DriveController::unstar/$1');
    $routes->post('items/(:num)/share', 'DriveController::share/$1');
    $routes->post('items/(:num)/share/revoke', 'DriveController::revokePublicShare/$1');
    $routes->post('items/(:num)/shares', 'DriveController::shareWithUser/$1');
    $routes->post('shares/(:num)/permission', 'DriveController::updateShare/$1');
    $routes->post('shares/(:num)/revoke', 'DriveController::revokeShare/$1');
});

$routes->group('admin', ['filter' => 'admin'], static function (RouteCollection $routes): void {
    $routes->get('dashboard', 'Admin\DashboardController::index');
    $routes->get('users', 'Admin\UsersController::index');
    $routes->get('users/(:num)', 'Admin\UsersController::show/$1');
    $routes->post('users/(:num)/status', 'Admin\UsersController::updateStatus/$1');
    $routes->post('users/(:num)/quota', 'Admin\UsersController::updateQuota/$1');
    $routes->post('users/(:num)/delete', 'Admin\UsersController::delete/$1');
    $routes->get('storage', 'Admin\StorageController::index');
    $routes->get('settings', 'Admin\SettingsController::index');
    $routes->post('settings', 'Admin\SettingsController::update');
    $routes->post('settings/maintenance', 'Admin\SettingsController::maintenance');
    $routes->get('announcements', 'Admin\AnnouncementsController::index');
    $routes->post('announcements', 'Admin\AnnouncementsController::create');
    $routes->post('announcements/bulk', 'Admin\AnnouncementsController::bulk');
    $routes->get('announcements/delivery', 'Admin\AnnouncementsController::delivery');
    $routes->post('announcements/(:num)/delete', 'Admin\AnnouncementsController::delete/$1');
    $routes->post('announcements/(:num)/publish', 'Admin\AnnouncementsController::publish/$1');
    $routes->post('announcements/(:num)/archive', 'Admin\AnnouncementsController::archive/$1');
    $routes->get('invitations', 'Admin\InvitationsController::index');
    $routes->get('audit', 'Admin\AuditController::index');
    $routes->post('audit/clear', 'Admin\AuditController::clear');
    $routes->post('audit/(:num)/delete', 'Admin\AuditController::delete/$1');
    $routes->post('invitations', 'Admin\InvitationsController::create');
    $routes->post('invitations/bulk', 'Admin\InvitationsController::bulk');
    $routes->post('invitations/(:num)/update', 'Admin\InvitationsController::update/$1');
    $routes->post('invitations/(:num)/delete', 'Admin\InvitationsController::delete/$1');
    $routes->post('invitations/(:num)/revoke', 'Admin\InvitationsController::revoke/$1');
});
