import urllib.request
import urllib.parse
import json
import ssl

# Configuration
NOTION_TOKEN = "YOUR_NOTION_TOKEN_HERE"
PARENT_PAGE_ID_RAW = "YOUR_PAGE_ID_HERE"

HEADERS = {
    "Authorization": "Bearer " + NOTION_TOKEN,
    "Content-Type": "application/json",
    "Notion-Version": "2022-06-28"
}

def format_uuid(raw_id):
    if len(raw_id) == 32:
        return f"{raw_id[:8]}-{raw_id[8:12]}-{raw_id[12:16]}-{raw_id[16:20]}-{raw_id[20:]}"
    return raw_id

PARENT_PAGE_ID = format_uuid(PARENT_PAGE_ID_RAW)

def make_request(url, method="GET", data=None):
    try:
        req = urllib.request.Request(url, headers=HEADERS, method=method)
        if data:
            req.data = json.dumps(data).encode("utf-8")
        
        context = ssl.create_default_context()
        with urllib.request.urlopen(req, context=context) as response:
            return json.loads(response.read().decode('utf-8'))
    except urllib.error.HTTPError as e:
        print(f"HTTP Error {e.code}: {e.read().decode('utf-8')}")
        return None
    except Exception as e:
        print(f"Error: {e}")
        return None

def create_page():
    url = "https://api.notion.com/v1/pages"
    
    # Define Page Content
    # Corrections:
    # 1. Annotations are a sibling of 'text', not inside 'text'.
    # 2. Link objects should be properly structured.
    
    children = [
        # Title usually part of page properties, headings here are body
        {
            "object": "block",
            "type": "heading_2",
            "heading_2": {
                "rich_text": [{"type": "text", "text": {"content": "什麼是 AI 緣分報告？"}}]
            }
        },
        {
            "object": "block",
            "type": "paragraph",
            "paragraph": {
                "rich_text": [{
                    "type": "text", 
                    "text": {"content": "SweetyAI 會派出專屬的 AI Agent，根據你的個人資料與偏好（年齡、居住地、是否接受遠距離等），在資料庫中尋找合適的對象。\n\n當找到潛在對象時，雙方的 Agent 會先進行「第一次接觸」，互相介紹老闆的優點與特質，並評估彼此的契合度（0-100分）。"}
                }]
            }
        },
        {
            "object": "block",
            "type": "heading_2",
            "heading_2": {
                "rich_text": [{"type": "text", "text": {"content": "如何開始？"}}]
            }
        },
        {
            "object": "block",
            "type": "paragraph",
            "paragraph": {
                "rich_text": [{"type": "text", "text": {"content": "請直接跟 SweetyAI 說："}}]
            }
        },
        {
            "object": "block",
            "type": "bulleted_list_item",
            "bulleted_list_item": {
                "rich_text": [{"type": "text", "text": {"content": "「修改我的個人資料」"}}]
            }
        },
        {
            "object": "block",
            "type": "bulleted_list_item",
            "bulleted_list_item": {
                "rich_text": [{"type": "text", "text": {"content": "接著設定你的：年齡、居住地、是否接受遠距離"}}]
            }
        },
        {
            "object": "block",
            "type": "paragraph",
            "paragraph": {
                "rich_text": [{"type": "text", "text": {"content": "設定完成後，AI 就會自動為你留意合適的人選囉！"}}]
            }
        },
        {
            "object": "block",
            "type": "heading_2",
            "heading_2": {
                "rich_text": [{"type": "text", "text": {"content": "收到報告後怎麼做？"}}]
            }
        },
        {
            "object": "block",
            "type": "paragraph",
            "paragraph": {
                "rich_text": [{"type": "text", "text": {"content": "如果雙方 Agent 評估的契合度都超過 70 分，你就會收到像下面這樣的「緣分報告」："}}]
            }
        },
        {
            "object": "block",
            "type": "callout",
            "callout": {
                "rich_text": [{"type": "text", "text": {"content": "👇 請在此處貼上緣分報告的截圖 👇"}}],
                "icon": {"emoji": "🖼️"}
            }
        },
        {
            "object": "block",
            "type": "paragraph",
            "paragraph": {
                "rich_text": [
                    {
                        "type": "text", 
                        "text": {"content": "只要點擊報告下方的 "},
                    },
                    {
                        "type": "text", 
                        "text": {"content": "「複製 ID」"},
                        "annotations": {"bold": True, "color": "green"}
                    },
                    {
                        "type": "text", 
                        "text": {"content": " 按鈕，然後將複製的內容直接貼給 SweetyAI，我就會幫你傳送第一則訊息給對方，開啟你們的對話！"}
                    }
                ]
            }
        },
        {
            "object": "block",
            "type": "divider",
            "divider": {}
        },
        {
            "object": "block",
            "type": "paragraph",
            "paragraph": {
                "rich_text": [
                    {
                        "type": "text", 
                        "text": {"content": "如果你還不知道 SweetyAI 是什麼，請參考：\n"}
                    },
                    {
                        "type": "text", 
                        "text": {
                            "content": "SweetyAI 官方介紹",
                            "link": {"url": "https://opaque-patella-d55.notion.site/SweetyAI-300e97c549f680a8b7cffbe1a8252d9c"}
                        }
                    }
                ]
            }
        }
    ]
    
    payload = {
        "parent": {"page_id": PARENT_PAGE_ID},
        "properties": {
            "title": [
                {
                    "text": {"content": "讓 SweetyAI 幫你結交朋友"}
                }
            ]
        },
        "children": children
    }
    
    print(f"Creating page under parent {PARENT_PAGE_ID}...")
    response = make_request(url, method="POST", data=payload)
    
    if response:
        print(f"✅ Page Created Successfully!")
        print(f"Title: 讓 SweetyAI 幫你結交朋友")
        print(f"URL: {response.get('url')}")
    else:
        print("❌ Failed to create page.")

if __name__ == "__main__":
    create_page()
