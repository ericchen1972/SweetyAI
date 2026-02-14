import json
import sys
import os
import re
from ftplib import FTP

CONFIG_FILE = 'sftp-config.json'

def load_config():
    if not os.path.exists(CONFIG_FILE):
        print(f"Error: {CONFIG_FILE} not found.")
        return None

    with open(CONFIG_FILE, 'r', encoding='utf-8') as f:
        content = f.read()
    
    # Remove single line comments // ...
    content = re.sub(r'\s*//.*', '', content)
    # Remove trailing commas (simple approach)
    content = re.sub(r',(\s*[}\]])', r'\1', content)
    
    try:
        return json.loads(content)
    except json.JSONDecodeError as e:
        print(f"Error parsing JSON: {e}")
        return None

def upload_file(local_path):
    config = load_config()
    if not config:
        return

    host = config.get('host')
    user = config.get('user')
    password = config.get('password')
    remote_root = config.get('remote_path', '/')
    port = int(config.get('port', 21))

    if not host or not user:
        print("Missing host or user in config")
        return

    # Normalize paths
    abs_local_path = os.path.abspath(local_path)
    cwd = os.getcwd()
    
    if not abs_local_path.startswith(cwd):
        print("File is not inside the current working directory Project.")
        return

    rel_path = os.path.relpath(abs_local_path, cwd)
    
    # Remote full path calculation
    # remote_root ends with /, join carefully
    if remote_root.endswith('/'):
        remote_file_path = remote_root + rel_path
    else:
        remote_file_path = remote_root + '/' + rel_path
        
    remote_file_path = remote_file_path.replace('\\', '/')
    remote_dir = os.path.dirname(remote_file_path)
    filename = os.path.basename(remote_file_path)

    print(f"Connecting to {host}...")
    try:
        ftp = FTP()
        ftp.connect(host, port, timeout=config.get('connect_timeout', 30))
        ftp.login(user, password)
        
        # Set passive mode based on config
        # Python ftplib defaults to passive=True (since 3.x usually, but let's be explicit)
        passive_mode = config.get('ftp_passive_mode', True)
        ftp.set_pasv(passive_mode)
        
        # Navigate to target directory, creating if necessary
        # We start from root or default?
        # If remote_path starts with /, we assume absolute path on server
        
        # Helper to change to directory, creating if missing
        def change_to_dir_recursive(path):
            if path == '/' or path == '.':
                ftp.cwd('/')
                return
            
            parts = path.split('/')
            # Handle absolute path
            if path.startswith('/'):
                ftp.cwd('/')
                parts.pop(0) # Remove empty first element

            for part in parts:
                if not part: continue
                try:
                    ftp.cwd(part)
                except Exception:
                    try:
                        print(f"Creating directory: {part}")
                        ftp.mkd(part)
                        ftp.cwd(part)
                    except Exception as e:
                        print(f"Failed to create/enter {part}: {e}")
                        raise

        change_to_dir_recursive(remote_dir)

        print(f"Uploading {filename}...")
        with open(abs_local_path, 'rb') as fp:
            ftp.storbinary(f'STOR {filename}', fp)
        
        print("Upload successful!")
        ftp.quit()
    except Exception as e:
        print(f"FTP Error: {e}")

if __name__ == "__main__":
    if len(sys.argv) < 2:
        print("Usage: python3 _agent_upload.py <filepath>")
    else:
        upload_file(sys.argv[1])
