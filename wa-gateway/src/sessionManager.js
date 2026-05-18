'use strict';

const QRCode = require('qrcode');
const axios = require('axios');
const pino = require('pino');
const path = require('path');
const fs = require('fs');

const logger = pino({ level: 'warn' });

let _baileys = null;

function getBaileys() {
    if (!_baileys) {
        _baileys = require('@whiskeysockets/baileys');
    }
    return _baileys;
}

class SessionManager {
    constructor() {
        // accountId → { socket, status, phone, connectedAt, callbackUrl, internalSecret, reconnectAttempts }
        this.sessions = new Map();
        // accountId → Map<normalizedPhone, originalJid>
        // Needed because @lid JIDs (WhatsApp privacy) cannot be replied via @s.whatsapp.net
        this.jidMap = new Map();
    }

    async startSession(accountId, callbackUrl, internalSecret) {
        // Stop existing session if any
        if (this.sessions.has(accountId)) {
            const existing = this.sessions.get(accountId);
            try { existing.socket?.ws?.close(); } catch (e) { /* ignore */ }
            this.sessions.delete(accountId);
        }

        const {
            default: makeWASocket,
            useMultiFileAuthState,
            DisconnectReason,
            fetchLatestBaileysVersion,
        } = getBaileys();
        const { Boom } = require('@hapi/boom');

        const sessionsDir = path.join('./sessions', accountId);
        fs.mkdirSync(sessionsDir, { recursive: true });

        const { state, saveCreds } = await useMultiFileAuthState(sessionsDir);
        const { version } = await fetchLatestBaileysVersion();

        const socket = makeWASocket({
            version,
            logger,
            auth: state,
            printQRInTerminal: false,
            browser: ['WA SaaS Agent', 'Chrome', '126.0.0'],
        });

        const sessionData = {
            socket,
            status: 'connecting',
            phone: null,
            connectedAt: null,
            callbackUrl,
            internalSecret,
            reconnectAttempts: 0,
        };
        this.sessions.set(accountId, sessionData);

        socket.ev.on('creds.update', saveCreds);

        socket.ev.on('connection.update', async (update) => {
            const { connection, lastDisconnect, qr } = update;

            if (qr) {
                try {
                    const qrBase64 = await QRCode.toDataURL(qr);
                    sessionData.status = 'qr_pending';
                    await this._postCallback(callbackUrl, internalSecret, {
                        event: 'qr',
                        qr_base64: qrBase64,
                    });
                } catch (err) {
                    console.error(`[SessionManager] QR error for ${accountId}:`, err.message);
                }
            }

            if (connection === 'close') {
                const statusCode = (lastDisconnect?.error instanceof Boom)
                    ? lastDisconnect.error.output?.statusCode
                    : 0;
                const loggedOut = statusCode === DisconnectReason.loggedOut;

                if (!loggedOut && sessionData.reconnectAttempts < 3) {
                    sessionData.reconnectAttempts++;
                    const delay = 5000 * sessionData.reconnectAttempts;
                    console.log(`[SessionManager] Reconnecting ${accountId} in ${delay}ms (attempt ${sessionData.reconnectAttempts})`);
                    setTimeout(() => {
                        this.startSession(accountId, callbackUrl, internalSecret).catch(console.error);
                    }, delay);
                } else {
                    const event = loggedOut ? 'disconnected' : 'failed';
                    sessionData.status = event;
                    this.sessions.delete(accountId);
                    await this._postCallback(callbackUrl, internalSecret, { event });
                }
            }

            if (connection === 'open') {
                const phone = socket.user?.id?.split(':')[0] || null;
                sessionData.status = 'connected';
                sessionData.phone = phone ? `+${phone}` : null;
                sessionData.connectedAt = new Date().toISOString();
                sessionData.reconnectAttempts = 0;
                await this._postCallback(callbackUrl, internalSecret, {
                    event: 'connected',
                    phone: sessionData.phone,
                });
            }
        });

        socket.ev.on('messages.upsert', async ({ messages, type }) => {
            if (type !== 'notify') return;

            const laravelUrl = process.env.LARAVEL_URL || 'http://app';
            const secret = internalSecret || process.env.INTERNAL_SECRET || '';

            for (const msg of messages) {
                if (msg.key.fromMe) continue;

                // skip group messages
                const jid = msg.key.remoteJid || '';
                if (jid.endsWith('@g.us') || jid.endsWith('@broadcast')) continue;

                const from = jid.split('@')[0];
                if (!from) continue;

                // Store original JID so replies use the correct JID type.
                // @lid JIDs (privacy mode) cannot be reached via @s.whatsapp.net.
                if (!this.jidMap.has(accountId)) this.jidMap.set(accountId, new Map());
                this.jidMap.get(accountId).set(from, jid);

                try {
                    await axios.post(`${laravelUrl}/webhook/inbound`, {
                        wa_account_id: accountId,
                        provider_message_id: msg.key.id,
                        from_phone: `+${from}`,
                        message_type: this._getMessageType(msg),
                        body: this._getMessageBody(msg),
                        media_url: null,
                        raw_payload: msg,
                        received_at: new Date().toISOString(),
                    }, {
                        headers: {
                            'X-Internal-Secret': secret,
                            'Content-Type': 'application/json',
                        },
                        timeout: 10000,
                    });
                } catch (err) {
                    console.warn(`[SessionManager] Failed to forward message to Laravel: ${err.message}`);
                }
            }
        });

        return { status: 'starting', account_id: accountId };
    }

    async stopSession(accountId) {
        const session = this.sessions.get(accountId);
        if (!session) return false;
        try { await session.socket.logout(); } catch (e) { /* ignore */ }
        this.sessions.delete(accountId);
        return true;
    }

    getStatus(accountId) {
        const session = this.sessions.get(accountId);
        if (!session) {
            return { status: 'disconnected', phone: null, connected_at: null };
        }
        return {
            status: session.status,
            phone: session.phone,
            connected_at: session.connectedAt,
        };
    }

    async sendMessage(accountId, toPhone, text) {
        const session = this.sessions.get(accountId);
        if (!session || session.status !== 'connected') {
            return { success: false, error: 'Session not found' };
        }
        try {
            const normalized = toPhone.replace('+', '');
            // Use stored original JID if available (handles @lid privacy JIDs).
            const jid = this.jidMap.get(accountId)?.get(normalized)
                ?? (normalized + '@s.whatsapp.net');
            const result = await session.socket.sendMessage(jid, { text });
            return { success: true, provider_message_id: result?.key?.id || null };
        } catch (err) {
            console.error(`[SessionManager] Send error for ${accountId}:`, err.message);
            return { success: false, error: err.message };
        }
    }

    async sendDocument(accountId, toPhone, fileUrl, caption = '', mimetype = 'application/pdf', fileName = null) {
        const session = this.sessions.get(accountId);
        if (!session || session.status !== 'connected') {
            return { success: false, error: 'Session not found' };
        }
        try {
            const normalized = toPhone.replace('+', '');
            const jid = this.jidMap.get(accountId)?.get(normalized)
                ?? (normalized + '@s.whatsapp.net');
            const payload = {
                document: { url: fileUrl },
                mimetype,
                caption: caption || undefined,
                fileName: fileName || this._fileNameFromUrl(fileUrl),
            };
            const result = await session.socket.sendMessage(jid, payload);
            return { success: true, provider_message_id: result?.key?.id || null };
        } catch (err) {
            console.error(`[SessionManager] Send document error for ${accountId}:`, err.message);
            return { success: false, error: err.message };
        }
    }

    _fileNameFromUrl(url) {
        try {
            const u = new URL(url);
            const last = u.pathname.split('/').filter(Boolean).pop();
            return last || 'document.pdf';
        } catch (e) {
            return 'document.pdf';
        }
    }

    _getMessageType(msg) {
        const m = msg.message;
        if (!m) return 'text';
        if (m.conversation || m.extendedTextMessage) return 'text';
        if (m.imageMessage) return 'image';
        if (m.audioMessage) return 'audio';
        if (m.documentMessage) return 'document';
        if (m.videoMessage) return 'video';
        if (m.stickerMessage) return 'sticker';
        return 'text';
    }

    _getMessageBody(msg) {
        const m = msg.message;
        if (!m) return null;
        return m.conversation || m.extendedTextMessage?.text || null;
    }

    async _postCallback(url, secret, data) {
        if (!url) return;
        try {
            await axios.post(url, data, {
                headers: {
                    'X-Internal-Secret': secret || '',
                    'Content-Type': 'application/json',
                },
                timeout: 5000,
            });
        } catch (err) {
            console.warn(`[SessionManager] Callback to ${url} failed: ${err.message}`);
        }
    }
}

module.exports = new SessionManager();
