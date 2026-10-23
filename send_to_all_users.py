import requests
import time
import re

# Configuration
BASE_URL = 'http://iran.mml-dev.ir/gpt_bot'  # Change to your actual base URL where PHP scripts are hosted
ADMIN_PASS = 'CHANGE_ME'  # Change to your actual admin password

# Endpoints
GET_USERS_ENDPOINT = f'{BASE_URL}/get_users.php'
SEND_MSG_ENDPOINT = f'{BASE_URL}/send_msg.php'


def extract_first_syllable(name: str):
    if not name:
        return ""

    # حذف فاصله‌های اضافی
    name = name.strip()

    # فقط حرف فارسی؟
    is_all_persian = bool(re.fullmatch(r"[آ-یءئ\s]+", name))

    # فقط حرف انگلیسی؟
    is_all_english = bool(re.fullmatch(r"[A-Za-z\s]+", name))

    if not (is_all_persian or is_all_english):
        return ""

    # بخش اول نام (تا اولین فاصله)
    first_part = name.split()[0]

    return first_part

def get_all_users():
    params = {'pass': ADMIN_PASS}
    response = requests.get(GET_USERS_ENDPOINT, params=params)
    response.raise_for_status()
    return response.json()


def send_message(chat_id, text):
    params = {
        'pass': ADMIN_PASS,
        'chatid': str(chat_id),
        'text': text
    }
    response = requests.get(SEND_MSG_ENDPOINT, params=params)
    response.raise_for_status()
    return response.json()


def main():
    try:
        users = get_all_users()
        print(f"Fetched {len(users)} users.")
        for user in users[0:-1]:
            chat_id = user.get('chat_id')
            if chat_id:
                name = user.get('first_name') or ""  # از first_name واقعی تلگرام استفاده کن
                first_name = extract_first_syllable(name)
                print(first_name)
                message1 = f"""سلام {first_name} 🔔  

امیدوارم حالت خوب باشه 😊  
* ربات به‌روزرسانی شده!* 🤖⚡️  

برای چت،
 فقط کافیه پیامت رو بفرستی. 💬✨  

/help –  اطلاعات و راهنما ℹ️
"""
                result = send_message(chat_id, message1)
                print(f"Sent message to chat_id {chat_id}: {result}")
            else:
                print(f"User without chat_id skipped: {user}")
            time.sleep(0.1)

    except Exception as e:
        print(f"Error: {e}")


if __name__ == '__main__':
    main()
    pass
message1 = """سلام دوستان! 🌟  

امیدوارم حالتون عالی باشه 😊  
ربات هوش مصنوعی ما به‌روزرسانی شد! 🤖✨

لطفاً ربات رو به دوستانتون هم معرفی کنید تا اون‌ها هم بتونن به راحتی ازش استفاده کنن. 💌👫

/help –  اطلاعات و راهنما ℹ️
/getpro –  دریافت *رایگان* امکانات PRO 🎁
/buypro –  خرید امکانات PRO 💳
"""
message1 = """سلام دوستان 🔔  

امیدوارم حالتون خوب باشه 😊  
* ربات به‌روزرسانی شده!* 🤖⚡️  

برای چت،
 فقط کافیه پیام خودتون رو بفرستید. 💬✨  

/help –  اطلاعات و راهنما ℹ️
"""
chat_id = '1349312486' 
chat_id = '1506092746' 
result = send_message(chat_id , message1)
print(f"Sent message to chat_id {chat_id }: {result}")
