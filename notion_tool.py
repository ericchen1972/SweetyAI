import urllib.request
import urllib.parse
import json
import sys
import ssl

# Configuration
NOTION_TOKEN = "YOUR_NOTION_TOKEN_HERE"
HEADERS = {
    "Authorization": "Bearer " + NOTION_TOKEN,
    "Content-Type": "application/json",
    "Notion-Version": "2022-06-28"
}

def make_request(url, method="GET", data=None):
    """Helper to make HTTP requests using urllib."""
    try:
        # Create request object
        req = urllib.request.Request(url, headers=HEADERS, method=method)
        
        # Prepare data if present
        if data:
            json_data = json.dumps(data).encode("utf-8")
            req.data = json_data
            
        # Create context (verify certs usually, but for simple scripts we just rely on system ca)
        context = ssl.create_default_context()
        
        with urllib.request.urlopen(req, context=context) as response:
            encoding = response.info().get_content_charset('utf-8')
            response_body = response.read().decode(encoding)
            
            # Notion returns 200 OK for successful get/post
            if response.status not in (200, 201, 202):
                 print(f"Error: Status Code {response.status}")
                 return None
            
            return json.loads(response_body)
            
    except urllib.error.HTTPError as e:
        print(f"HTTP Error: {e.code} - {e.reason}")
        print(e.read().decode('utf-8')) # Print response body for debugging
        return None
    except Exception as e:
        print(f"Error making request: {e}")
        return None

def search_page(page_name):
    """
    Search for a page by title using the Notion Search API.
    Returns (page_id, page_title) if found, else (None, None).
    """
    url = "https://api.notion.com/v1/search"
    payload = {
        "query": page_name,
        "filter": {
            "value": "page",
            "property": "object"
        },
        "sort": {
            "direction": "descending",
            "timestamp": "last_edited_time"
        }
    }
    
    data = make_request(url, method="POST", data=payload)
    if not data:
        return None, None
        
    results = data.get("results", [])
    
    # Try to find an exact match first
    for page in results:
        if "properties" in page:
            title_text = "Untitled"
            # Attempt to extract title
            for prop_name, prop_val in page["properties"].items():
                if prop_val["type"] == "title":
                    title_parts = prop_val.get("title", [])
                    if title_parts:
                        title_text = "".join([t["plain_text"] for t in title_parts])
                    break
            
            # Check match (case-insensitive)
            if page_name.lower() in title_text.lower():
                return page["id"], title_text
    
    # If no loop match but results exist, return the first one
    if results:
        first_page = results[0]
        title_text = "Untitled"
        if "properties" in first_page:
            for prop_name, prop_val in first_page["properties"].items():
                if prop_val["type"] == "title":
                    title_parts = prop_val.get("title", [])
                    if title_parts:
                        title_text = "".join([t["plain_text"] for t in title_parts])
                    break
        return first_page["id"], title_text
        
    return None, None

def read_page_blocks(page_id):
    """
    Retrieve the blocks (children) of a page.
    """
    url = f"https://api.notion.com/v1/blocks/{page_id}/children"
    data = make_request(url, method="GET")
    if data:
        return data.get("results", [])
    return []

def append_paragraph(page_id, text):
    """
    Append a simple paragraph block to the page.
    """
    url = f"https://api.notion.com/v1/blocks/{page_id}/children"
    payload = {
        "children": [
            {
                "object": "block",
                "type": "paragraph",
                "paragraph": {
                    "rich_text": [
                        {
                            "type": "text",
                            "text": {
                                "content": text
                            }
                        }
                    ]
                }
            }
        ]
    }
    
    data = make_request(url, method="PATCH", data=payload)
    return data is not None

def demo(query_name=None, content_to_add=None):
    print(f"--- Notion Tool Demo (No Dependencies) ---")
    
    # If query_name is empty, just list accessible pages
    if not query_name:
        print("Listing accessible pages:")
        page_id, title = search_page("")
        # The search_page function returns the first match, but let's just see if it works.
        # Actually, let's just rely on user input for now.
        if page_id:
             print(f"✅ Connection Successful! Found at least one page: '{title}' ({page_id})")
        else:
             print("❌ Connection successful, but no pages found OR Invalid Token/Scopes.")
             print("💡 Ensure the integration is connected to pages!")
        return

    print(f"Target Page Name Query: '{query_name}'")
    
    # 1. Search
    page_id, found_title = search_page(query_name)
    
    if not page_id:
        print(f"❌ Page matching '{query_name}' not found.")
        print("💡 Hint: Ensure you have connected the Integration to the specific page via the '...' menu -> 'Add connections'.")
        return

    print(f"✅ Found Page: '{found_title}'")
    print(f"   ID: {page_id}")
    
    # 2. Read
    blocks = read_page_blocks(page_id)
    print(f"   Current Block Count: {len(blocks)}")
    if blocks:
        print("   First few blocks types:")
        for b in blocks[:3]:
            print(f"     - {b.get('type')}")
    
    # 3. Add Content (if provided)
    if content_to_add:
        print(f"Attempting to add content: '{content_to_add}'...")
        if append_paragraph(page_id, content_to_add):
            print("✅ Content appended successfully!")
        else:
            print("❌ Failed to append content.")
    else:
        print("ℹ️ No content provided to add. (Usage: python3 notion_tool.py <page_name> \"<content arg>\")")

# CLI Entry Point
if __name__ == "__main__":
    p_name = None
    c_text = None
    
    if len(sys.argv) > 1:
        p_name = sys.argv[1]
    if len(sys.argv) > 2:
        c_text = sys.argv[2]
        
    demo(p_name, c_text)
