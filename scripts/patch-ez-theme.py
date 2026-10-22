#!/usr/bin/env python3
"""Patch the deployed ez-theme build without changing the upstream API.

Usage: python3 scripts/patch-ez-theme.py /path/to/pristine/dist /path/to/new/dist
The input is never modified. Unknown builds fail before any output is written.
"""
import argparse
import hashlib
import json
from pathlib import Path
import shutil


def replace_once(text, old, new):
    if text.count(old) != 1:
        raise ValueError(f"Unsupported ez-theme build: expected one occurrence of {old!r}")
    return text.replace(old, new)


def patch(source, target):
    if target.exists():
        raise ValueError("Output directory must not exist")
    source = source.resolve()
    target = target.resolve()
    if source == target or source in target.parents:
        raise ValueError("Output must be outside the original dist")
    js = source / "static/js"
    changes = {}
    router = js / "310.74194512.js"
    changes[router.name] = replace_once(
        router.read_text(), '{path:"payment",name:"Payment",',
        '{path:"order/:trade_no",redirect:function(e){return{path:"/payment",'
        'query:Object.assign({},e.query,{trade_no:e.params.trade_no})}}},'
        '{path:"payment",name:"Payment",',
    )
    for name, form, errors, translate in [
        ("16.4db5e8d1.js", "C", "b", "i"),
        ("999.23832e22.js", "S", "F", "s"),
    ]:
        text = (js / name).read_text()
        old = (f'{form}.newPassword?{form}.newPassword.length<8&&'
               f'({errors}.newPassword={translate}("auth.passwordTooShort"),o=!1):')
        new = (f'{form}.email.length>64&&({errors}.email="邮箱最多为 64 个字符",o=!1),'
               f'{form}.newPassword?({form}.newPassword.length<8&&'
               f'({errors}.newPassword={translate}("auth.passwordTooShort"),o=!1),'
               f'{form}.newPassword.length>64&&'
               f'({errors}.newPassword="密码最多为 64 个字符",o=!1)):')
        text = replace_once(text, old, new)
        for field in ["email", "newPassword", "confirmPassword"]:
            text = replace_once(text, f'id:"{field}",', f'id:"{field}",maxlength:64,')
        changes[name] = text
    # Keep leading zeroes and match upstream's string + six digits contract.
    for name, form in [
        ("16.4db5e8d1.js", "C"), ("999.23832e22.js", "S"),
        ("672.960c5ab5.js", "x"), ("674.61b87ce1.js", "P"),
    ]:
        text = changes.get(name, (js / name).read_text())
        changes[name] = replace_once(
            text, f'parseInt({form}.verificationCode)', f'String({form}.verificationCode)',
        )
    entries = list(js.glob("index.*.js"))
    if len(entries) != 1:
        raise ValueError("Expected one original webpack entry")
    entry = entries[0]
    runtime = entry.read_text()
    names = {}
    for name, text in changes.items():
        chunk, old_hash, _ = name.split(".")
        digest = hashlib.sha256(text.encode()).hexdigest()[:8]
        names[name] = f"{chunk}.{digest}.js"
        runtime = replace_once(runtime, f'{chunk}:"{old_hash}"', f'{chunk}:"{digest}"')
    new_entry = f"index.{hashlib.sha256(runtime.encode()).hexdigest()[:8]}.js"
    names[entry.name] = new_entry
    changes[entry.name] = runtime
    html = (source / "index.html").read_text()
    for old, new in names.items():
        html = html.replace(f"static/js/{old}", f"static/js/{new}")
    if f"static/js/{new_entry}" not in html:
        raise ValueError("Entry script missing from index.html")
    shutil.copytree(source, target)
    manifest = {"changes": []}
    # Retain old chunks so open tabs can finish using the previous runtime.
    for old, new in names.items():
        text = changes[old]
        (target / "static/js" / new).write_text(text)
        manifest["changes"].append({
            "old": f"static/js/{old}", "new": f"static/js/{new}",
            "before_sha256": hashlib.sha256((js / old).read_bytes()).hexdigest(),
            "after_sha256": hashlib.sha256(text.encode()).hexdigest(),
        })
    (target / "index.html").write_text(html)
    (target / "ez-upstream-compat.json").write_text(json.dumps(manifest, indent=2) + "\n")
    return manifest


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("source", type=Path)
    parser.add_argument("target", type=Path)
    args = parser.parse_args()
    print(json.dumps(patch(args.source, args.target), indent=2))
