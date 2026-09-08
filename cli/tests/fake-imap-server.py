#!/usr/bin/env python3
"""
Minimal IMAP server, just enough to exercise the ImapNative connector.

Speaks the handful of commands the connector actually issues -- CAPABILITY,
LOGIN, LIST, EXAMINE/SELECT, UID SEARCH, UID FETCH -- over plain TCP. It is a
test fixture, not an IMAP implementation: it holds a fixed mailbox in memory,
including a folder whose name is encoded in modified UTF-7, which is the case
that used to lose messages.

Usage: fake-imap-server.py <port>   (prints "READY" once listening)
"""
import datetime
import email.utils
import re
import socketserver
import sys
import threading

# "INBOX.Éléments envoyés" in modified UTF-7, as a real server would name it.
SENT = "INBOX.&AMk-l&AOk-ments envoy&AOk-s"

def message(sender, subject, date, body):
    header = (
        f"From: {sender}\r\n"
        f"To: test@mailbxzip.com\r\n"
        f"Subject: {subject}\r\n"
        f"Date: {date}\r\n"
        "Content-Type: text/plain; charset=UTF-8\r\n"
    )
    return header, body + "\r\n"

MAILBOX = {
    "INBOX": {
        101: message("yann@mailbxzip.com", "Bonjour", "Mon, 8 Jan 2024 22:41:44 +0100", "Hello world!"),
        102: message("contact@example.org", "Facture", "Mon, 15 Jan 2024 09:02:00 +0100", "Ci-joint la facture."),
        # Outside a 2024 window, on either side.
        103: message("vieux@example.org", "Archive", "Tue, 12 Dec 2023 08:00:00 +0100", "Message de 2023."),
        104: message("neuf@example.org", "Recent", "Thu, 9 Jan 2025 08:00:00 +0100", "Message de 2025."),
    },
    SENT: {
        201: message("test@mailbxzip.com", "Re: proposition", "Tue, 16 Jan 2024 18:30:00 +0100", "Bien recu."),
    },
    "INBOX.Brouillons": {},
}


class Handler(socketserver.StreamRequestHandler):

    def send(self, line):
        self.wfile.write((line + "\r\n").encode())
        self.wfile.flush()

    def handle(self):
        self.selected = None
        self.send("* OK fake-imap ready")

        while True:
            raw = self.rfile.readline()
            if not raw:
                return

            parts = raw.decode(errors="replace").strip().split(" ")
            if len(parts) < 2:
                continue

            tag, command, args = parts[0], parts[1].upper(), parts[2:]

            if command == "CAPABILITY":
                self.send("* CAPABILITY IMAP4rev1")
                self.send(f"{tag} OK CAPABILITY completed")
            elif command == "LOGIN":
                self.send(f"{tag} OK LOGIN completed")
            elif command == "LIST":
                for name in MAILBOX:
                    self.send(f'* LIST (\\HasNoChildren) "." "{name}"')
                self.send(f"{tag} OK LIST completed")
            elif command in ("SELECT", "EXAMINE"):
                self.selected = self.unquote(" ".join(args))
                count = len(MAILBOX.get(self.selected, {}))
                self.send(f"* {count} EXISTS")
                self.send("* 0 RECENT")
                self.send(f"{tag} OK [READ-ONLY] {command} completed")
            elif command == "UID" and args and args[0].upper() == "SEARCH":
                uids = self.search(args[1:])
                self.send(("* SEARCH " + " ".join(str(u) for u in uids)).rstrip())
                self.send(f"{tag} OK SEARCH completed")
            elif command == "UID" and args and args[0].upper() == "FETCH":
                self.fetch(tag, args[1], " ".join(args[2:]))
            elif command == "LOGOUT":
                self.send("* BYE")
                self.send(f"{tag} OK LOGOUT completed")
                return
            else:
                self.send(f"{tag} OK {command} ignored")

    def search(self, criteria):
        """Support ALL plus the SENTSINCE / SENTBEFORE date criteria."""
        folder = MAILBOX.get(self.selected, {})
        tokens = [c.strip('"') for c in criteria]
        since = before = None

        for index, token in enumerate(tokens):
            key = token.upper()
            if key in ("SENTSINCE", "SENTBEFORE") and index + 1 < len(tokens):
                bound = datetime.datetime.strptime(tokens[index + 1], "%d-%b-%Y").date()
                if key == "SENTSINCE":
                    since = bound
                else:
                    before = bound

        kept = []
        for uid in sorted(folder):
            sent = self.sent_date(folder[uid][0])
            # SENTSINCE is inclusive, SENTBEFORE is exclusive.
            if since and (sent is None or sent < since):
                continue
            if before and (sent is None or sent >= before):
                continue
            kept.append(uid)
        return kept

    @staticmethod
    def sent_date(header):
        match = re.search(r"^Date:\s*(.+)$", header, re.MULTILINE)
        if not match:
            return None
        parsed = email.utils.parsedate_to_datetime(match.group(1).strip())
        return parsed.date() if parsed else None

    def fetch(self, tag, sequence, item):
        wanted = "RFC822.HEADER" if "HEADER" in item.upper() else "RFC822.TEXT"
        folder = MAILBOX.get(self.selected, {})

        for seq, uid in enumerate(self.expand(sequence, folder), start=1):
            header, body = folder.get(uid, ("", ""))
            payload = (header + "\r\n") if wanted == "RFC822.HEADER" else body
            encoded = payload.encode()

            # Literals carry their byte length, which is how the client knows
            # where the payload ends.
            self.wfile.write(f"* {seq} FETCH (UID {uid} {wanted} {{{len(encoded)}}}\r\n".encode())
            self.wfile.write(encoded)
            self.wfile.write(b")\r\n")

        self.wfile.flush()
        self.send(f"{tag} OK FETCH completed")

    @staticmethod
    def expand(sequence, folder):
        """Turn an IMAP sequence set ("101", "101:103", "1,5:7") into uids."""
        uids = []
        for part in sequence.split(","):
            if ":" in part:
                low, high = part.split(":", 1)
                low = int(low)
                high = max(folder) if high == "*" else int(high)
                uids.extend(u for u in sorted(folder) if low <= u <= high)
            elif int(part) in folder:
                uids.append(int(part))
        return uids

    @staticmethod
    def unquote(value):
        value = value.strip()
        return value[1:-1] if value.startswith('"') and value.endswith('"') else value


class Server(socketserver.ThreadingTCPServer):
    allow_reuse_address = True
    daemon_threads = True


if __name__ == "__main__":
    port = int(sys.argv[1]) if len(sys.argv) > 1 else 1143
    server = Server(("127.0.0.1", port), Handler)
    threading.Thread(target=server.serve_forever, daemon=True).start()
    print("READY", flush=True)
    try:
        threading.Event().wait()
    except KeyboardInterrupt:
        server.shutdown()
