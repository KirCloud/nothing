#!/usr/bin/env python3
"""Loopback-only synthetic EPay gateway for the local integration workflow."""
import hashlib
from http.server import BaseHTTPRequestHandler, HTTPServer
from html import escape
from urllib.parse import parse_qs, urlencode, urlsplit
from urllib.request import urlopen


class Gateway(BaseHTTPRequestHandler):
    def do_GET(self):
        request = urlsplit(self.path)
        params = {k: v[0] for k, v in parse_qs(request.query).items()}
        if request.path == "/submit.php":
            body = ('<meta charset="utf-8"><h1>本地模拟支付</h1>'
                    '<p>订单 ' + escape(params["out_trade_no"]) + '</p>'
                    '<a href="/complete?' + escape(urlencode(params), quote=True)
                    + '">模拟支付成功并返回</a>').encode()
            self.send_response(200)
            self.send_header("Content-Type", "text/html; charset=utf-8")
            self.end_headers()
            self.wfile.write(body)
        elif request.path == "/complete":
            assert params["notify_url"].startswith("http://127.0.0.1:18080/api/v1/guest/payment/notify/EPay/")
            assert params["return_url"].startswith("http://127.0.0.1:18081/#/order/")
            notify = {"out_trade_no": params["out_trade_no"], "trade_no": "synthetic-browser-payment",
                      "trade_status": "TRADE_SUCCESS", "money": params["money"], "pid": "local"}
            signature = "&".join(k + "=" + notify[k] for k in sorted(notify)) + "synthetic-key"
            notify["sign"] = hashlib.md5(signature.encode()).hexdigest()
            notify["sign_type"] = "MD5"
            with urlopen(params["notify_url"] + "?" + urlencode(notify), timeout=10) as response:
                assert response.read() == b"success"
            self.send_response(302)
            self.send_header("Location", params["return_url"])
            self.end_headers()
        else:
            self.send_error(404)


if __name__ == "__main__":
    HTTPServer(("127.0.0.1", 18082), Gateway).serve_forever()
