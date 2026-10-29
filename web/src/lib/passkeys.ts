import type { PublicKeyCredentialCreationOptionsJSON, PublicKeyCredentialRequestOptionsJSON } from '@simplewebauthn/browser';
import { ApiError, call, markSignedIn } from './account';

/**
 * Passkey sign-in (fingerprint, face or screen lock) against /api/v1 (laravel/passkeys).
 * The WebAuthn helper is only downloaded when a passkey is actually used.
 */
const webauthn = () => import('@simplewebauthn/browser');

export interface PasskeyRow {
  id: number;
  name: string;
  authenticator: string | null;
  created_at: string;
  last_used_at: string | null;
}

/** Every current browser has WebAuthn; older ones keep the email code. */
export const passkeysSupported = () => typeof window !== 'undefined' && 'PublicKeyCredential' in window;

/** Chrome, Safari and Edge can offer saved passkeys in the email field's autofill list. */
export async function passkeyAutofillSupported() {
  return passkeysSupported() && (await webauthn()).browserSupportsWebAuthnAutofill();
}

/** Does this device have its own fingerprint/face/PIN unlock (so offering to create a passkey makes sense)? */
export async function deviceCanCreatePasskey() {
  return passkeysSupported() && (await webauthn()).platformAuthenticatorIsAvailable();
}

/** Usernameless: the device shows the passkeys it has for this site. With autofill, waits for the shopper to pick one. */
export async function signInWithPasskey({ autofill = false } = {}) {
  const { startAuthentication } = await webauthn();
  const { options } = await call<{ options: PublicKeyCredentialRequestOptionsJSON }>('GET', 'auth/passkey/options');
  const credential = await startAuthentication({ optionsJSON: options, useBrowserAutofill: autofill });
  const { user } = await call<{ user: { id: number; name: string; email: string } }>('POST', 'auth/passkey', { credential, remember: true });
  markSignedIn();
  return user;
}

export async function addPasskey() {
  const { startRegistration } = await webauthn();
  const { options } = await call<{ options: PublicKeyCredentialCreationOptionsJSON }>('GET', 'passkeys/options');
  const credential = await startRegistration({ optionsJSON: options });
  return call<{ id: string; name: string }>('POST', 'passkeys', { name: deviceName(), credential });
}

export const listPasskeys = () => call<PasskeyRow[]>('GET', 'passkeys');
export const removePasskey = (id: number) => call('DELETE', `passkeys/${id}`);

/** A message for the shopper, or null when they closed the prompt themselves. */
export function passkeyError(error: unknown): string | null {
  if (error instanceof ApiError) return error.message;
  const { name, code } = (error ?? {}) as { name?: string; code?: string };
  if (name === 'NotAllowedError' || name === 'AbortError' || code === 'ERROR_CEREMONY_ABORTED') return null;
  if (code === 'ERROR_AUTHENTICATOR_PREVIOUSLY_REGISTERED') return 'This device already has a passkey for your account.';
  return 'Passkeys didn’t work on this device. Please use the email code instead.';
}

/** "Passkey on iPhone": helps the shopper tell their passkeys apart on the account page. */
function deviceName() {
  const ua = navigator.userAgent;
  const device =
    [['iPhone', /iPhone/], ['iPad', /iPad/], ['Android', /Android/], ['Mac', /Mac OS X/], ['Windows', /Windows/], ['Chromebook', /CrOS/], ['Linux', /Linux/]].find(
      ([, pattern]) => (pattern as RegExp).test(ua),
    )?.[0] ?? 'this device';
  return `Passkey on ${device}`;
}
