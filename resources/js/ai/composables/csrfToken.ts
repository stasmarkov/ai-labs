/**
 * The CSRF token Laravel keeps in the XSRF-TOKEN cookie. A same-site `fetch` sends it back in the X-XSRF-TOKEN header.
 */
export function csrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}
