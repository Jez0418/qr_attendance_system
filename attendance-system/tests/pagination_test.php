<?php
/**
 * Run: php attendance-system/tests/pagination_test.php
 * render_pagination() must stay short however many pages there are (50 pages used to be 50 buttons in one row,
 * which spilled out of the card on the Attendance Monitoring page). No database needed. tests/ is not deployed.
 */
require_once __DIR__ . '/../includes/functions.php';

$fail = 0;
function check(string $name, bool $ok): void { global $fail; echo ($ok ? 'PASS' : 'FAIL') . "  $name\n"; if (!$ok) $fail++; }

function pages_html(int $page, int $total, array $get = []): string {
    $_GET = $get;
    ob_start();
    render_pagination($page, $total);
    return ob_get_clean();
}
function labels(string $html): array {
    preg_match_all('~<(?:a|span) class="page-link"[^>]*>(.*?)</(?:a|span)>~', $html, $m);
    return $m[1];
}

// Which page numbers are shown (0 = a gap shown as an ellipsis)
check('1 of 50: first, window, gap, last', pagination_pages(1, 50) === [1, 2, 3, 0, 50]);
check('25 of 50: first, gap, window, gap, last', pagination_pages(25, 50) === [1, 0, 23, 24, 25, 26, 27, 0, 50]);
check('50 of 50: first, gap, window', pagination_pages(50, 50) === [1, 0, 48, 49, 50]);
check('a gap of one page shows that page, not an ellipsis', pagination_pages(5, 9) === [1, 2, 3, 4, 5, 6, 7, 8, 9]);
check('a gap of two pages is an ellipsis', pagination_pages(5, 10) === [1, 2, 3, 4, 5, 6, 7, 0, 10]);
check('few pages are all shown', pagination_pages(3, 5) === [1, 2, 3, 4, 5]);

// The HTML
check('one page prints nothing', pages_html(1, 1) === '');
$html = pages_html(25, 50);
check('50 pages stay short (at most 9 numbers + 2 arrows + 2 ellipses)', count(labels($html)) <= 13);
check('current page is marked active and aria-current', substr_count($html, 'page-item active') === 1 && substr_count($html, 'aria-current="page"') === 1);
check('first page has no previous arrow', strpos(pages_html(1, 50), 'aria-label="Previous page"') === false);
check('last page has no next arrow', strpos(pages_html(50, 50), 'aria-label="Next page"') === false);
check('middle page has both arrows', strpos($html, 'aria-label="Previous page"') !== false && strpos($html, 'aria-label="Next page"') !== false);
check('previous arrow goes to page 24, next to page 26', strpos($html, 'page=24"') !== false && strpos($html, 'page=26"') !== false);
check('ellipses are not links', substr_count($html, '<span class="page-link">…</span>') === 2);

// Filters survive and are escaped
$html = pages_html(2, 9, ['search' => 'a b&c', 'status' => 'Late', 'page' => '2']);
check('filters are kept in the links', strpos($html, 'search=a+b%26c&amp;status=Late&amp;page=3') !== false);
check('no raw & in an href', !preg_match('~href="[^"]*&(?!amp;)~', $html));

echo $fail ? "\n$fail FAILED\n" : "\nAll passed\n";
exit($fail ? 1 : 0);
