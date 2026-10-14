<?php

namespace App\Controllers;

use App\Models\UserProfileModel;
use CodeIgniter\HTTP\RedirectResponse;

class LanguageController extends BaseController
{
    public function switch(): RedirectResponse
    {
        $locale = (string) $this->request->getPost('locale');
        if (! in_array($locale, ['en', 'id'], true)) {
            $locale = 'en';
        }

        session()->set('locale', $locale);
        if (session('is_logged_in') && is_numeric(session('user_id'))) {
            try {
                $profiles = new UserProfileModel();
                $userId = (int) session('user_id');
                if ($profiles->find($userId) === null) {
                    $profiles->insert(['user_id' => $userId, 'locale' => $locale]);
                } else {
                    $profiles->update($userId, ['locale' => $locale]);
                }
            } catch (\Throwable $exception) {
                if (! str_contains(mb_strtolower($exception->getMessage()), 'locale')) {
                    throw $exception;
                }
            }
        }

        $message = lang('App.flash.languageUpdated', [], $locale);
        $returnTo = (string) $this->request->getPost('return_to');
        $indexPage = trim((string) config(\Config\App::class)->indexPage, '/');
        if ($indexPage !== '') {
            $returnTo = preg_replace('~^(?:/' . preg_quote($indexPage, '~') . ')+(?=/|\?|$)~', '', $returnTo);
        }
        if ($this->isSafeReturnPath($returnTo)) {
            return redirect()->to($returnTo)->with('message', $message);
        }

        return redirect()->to(session('is_logged_in') ? '/dashboard' : '/login')->with('message', $message);
    }

    private function isSafeReturnPath(string $path): bool
    {
        return $path !== ''
            && str_starts_with($path, '/')
            && ! str_starts_with($path, '//')
            && ! preg_match('~/(avatar|download|preview|thumbnail)(/|$)~i', $path)
            && ! str_contains($path, '\\')
            && ! preg_match('/[\r\n]/', $path);
    }
}
