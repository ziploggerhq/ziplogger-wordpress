// The official ZipLogger browser client, with the plugin in charge of identity.
//
// The SDK mints an anonymous id (localStorage) and a session id (sessionStorage) for itself. The plugin
// must decide WHEN those may exist (consent), so it always hands the SDK both ids explicitly - the SDK
// then never touches storage - and changes them through setIdentity() when consent changes. The SDK's
// own identity() getter, track(), identify() and the replay module all read the same fields, which is
// what keeps logs, events, traces and replays on ONE session id.
//
// setIdentity() writes fields the SDK marks private. That is deliberate and pinned: the SDK version is
// fixed in package.json and bundled, and test/client.test.mjs fails if an upgrade changes the behaviour.

import { ZipLoggerBrowser } from '@ziplogger/browser';

export class WPClient extends ZipLoggerBrowser {
  /**
   * @param {{userId?:string|null, anonymousId?:string|null, sessionId?:string|null}} id
   */
  setIdentity(id) {
    this._userId = id.userId || null;
    this._anonymousId = id.anonymousId || null;
    this._sessionId = id.sessionId || null;
  }
}
