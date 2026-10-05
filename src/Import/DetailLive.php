<?php

declare(strict_types=1);

namespace AA\Import;

use AA\Api\JsonResponse;
use AA\Util\UiPrefs;

/**
 * What the script of the import detail page asks for while a run goes on (javascript/dataimport.js),
 * every few seconds and maybe all night: ?progress=<run> - the JSON of DetailPage::progressJson() - and
 * ?sections=1 - the HTML of DetailPage::sectionsAnswer(). The session is opened as for an anonymous
 * visitor, so an expired login gets a JSON 401 instead of the login form, and closed unwritten: polling
 * neither keeps the login alive nor holds the session lock of the user's other pages.
 */
final class DetailLive
{
    /**
     * Opens the session, closes it unwritten, answers. The caller has loaded the AA bootstrap files
     * (not init_page.php, whose login form and request log this skips) and buffers their output.
     * @param array<mixed> $get $_GET
     */
    public static function answer(array $get): JsonResponse|string
    {
        pageOpen('nobody');
        $auth = $GLOBALS['auth'] ?? null;
        $uid  = ($auth instanceof \AA_Auth) && $auth->is_user() ? AuthGetUid() : '';
        session_abort();

        $slice = $get['change_id'] ?? null;
        if (($uid !== '') && !self::mayOpenModule($slice)) {
            return DetailPage::refused();
        }
        if (isset($get['progress'])) {
            return DetailPage::progressJson($slice, $get['id'] ?? null, $get['progress'], $uid);
        }
        return DetailPage::sectionsAnswer($slice, $get['id'] ?? null, $uid, Run::clock(), self::useModule(...));
    }

    /** sends what answer() gave: JSON with its status, or the HTML of the sections */
    public static function emit(JsonResponse|string $answer): void
    {
        foreach (self::headers($answer) as $header) {
            header($header);
        }
        if ($answer instanceof JsonResponse) {
            $answer->emit();
            return;
        }
        http_response_code(200);
        echo $answer;
    }

    /** @return list<string> the headers of the answer - as AA::sendAdminHeaders() sends for a page,
     *                       and framing allowed only by the AA's own pages, the sections carry buttons */
    public static function headers(JsonResponse|string $answer): array
    {
        return [
            'Content-Type: ' . (($answer instanceof JsonResponse) ? 'application/json' : 'text/html') . '; charset=utf-8',
            'X-Content-Type-Options: nosniff',
            'X-Frame-Options: SAMEORIGIN',
            "Content-Security-Policy: frame-ancestors 'self'",
            'Referrer-Policy: strict-origin-when-cross-origin',
        ];
    }

    /** What init_page.php lets through to every admin page of a module: a superadmin, or a user with
     *  a role in the module itself (not only one in the whole AA) whose module is not deleted. */
    private static function mayOpenModule(mixed $slice): bool
    {
        if (!is_string($slice) || (preg_match(DetailPage::ID, $slice) !== 1)) {
            return false;
        }
        if (IsSuperadmin()) {
            return true;
        }
        $slices = GetUserSlices();
        return (($slices === 'all') || !empty($slices[$slice])) && !\AA_Module::getModule($slice)?->deleted();
    }

    /** the sections as their page renders them: in the language of the slice and with its module id
     *  in the links, as init_page.php sets both */
    private static function useModule(string $slice): void
    {
        $lang_file = (string) (\AA_Module::getModuleProperty($slice, 'lang_file') ?: constant('DEFAULT_LANG_INCLUDE'));
        bind_mgettext_domain(UiPrefs::langOverrideFile($lang_file));
        \AA::$module_id = $slice;
    }
}
