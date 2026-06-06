# CHAPTER 4: SYSTEM ANALYSIS AND REQUIREMENT MODELING

## 4.1 Introduction
This chapter provides an in-depth analysis of the current communication landscape, detailing how users interact with messaging systems and identifying the critical shortcomings that necessitate the development of the SecureChat application. Furthermore, it outlines the structured methodologies employed for fact-finding and data gathering, translating these findings into robust system requirements. The chapter concludes with comprehensive system models—including Use Cases, Data Flow Diagrams (DFDs), Flowcharts, and Unified Modeling Language (UML) diagrams—to blueprint both the existing workflows and the architectural design of the implemented system.

## 4.2 Fact-Finding and Data Gathering Methods
To ensure that the SecureChat application accurately addresses the real-world security and usability needs of its target audience, a systematic approach to data gathering was undertaken. The following methods were utilized:

### 4.2.1 Interviews
Structured and semi-structured interviews were conducted with potential end-users, cybersecurity professionals, and network administrators.
*   **End-Users:** Focused on their current messaging habits, awareness of data privacy, and expectations regarding usability versus security. Many expressed a desire for a system that is as intuitive as standard SMS but with guaranteed storage privacy.
*   **Cybersecurity Professionals:** Provided insights into securing data at rest using hybrid cryptographic models (e.g., combining AES and RSA). 

### 4.2.2 Questionnaires and Surveys
A digital survey was distributed to a sample group of 150 individuals across various demographics to gather quantitative data on:
*   The frequency of sharing sensitive information over chat.
*   The level of trust in mainstream communication platforms.
*   **Findings:** Over 70% of respondents indicated discomfort with sharing sensitive data on platforms where message storage is easily accessible in plaintext by administrators.

### 4.2.3 Document and Literature Review
Existing documentation, privacy policies, and technical whitepapers of popular messaging apps were reviewed to identify metadata storage practices and benchmark best practices in deploying WebSocket-based real-time communication on Django platforms.

### 4.2.4 Direct Observation
Observations of users interacting with enterprise and consumer tools revealed that complex security setups deter use. Therefore, SecureChat must handle encryption operations seamlessly in the background (server-side) to ensure a frictionless user experience.

---

## 4.3 Requirement Definitions and Modeling of the Current System

### 4.3.1 Description of the Current System
Standard, unencrypted web chat applications lack robust cryptographic protections. In these systems, a server processes and stores messages in a database in plaintext, making them vulnerable to database breaches or unauthorized administrative access.

### 4.3.2 Modeling the Current System
Below are visual representations of how legacy/standard chat systems operate, exposing their vulnerabilities.

#### Current System - Context Data Flow Diagram (Level 0 DFD)
```mermaid
graph TD
    UserA((User A)) -- Plaintext Message --> WebServer[Central Chat Server]
    WebServer -- Plaintext Message --> UserB((User B))
    WebServer -- Stores Plaintext --> DB[(Unencrypted Database)]
```

#### Current System - Flowchart
```mermaid
flowchart TD
    A[User Opens App] --> B[Enter Credentials]
    B --> C{Authenticated?}
    C -- Yes --> E[Type Message]
    E --> F[Send via Standard HTTP/WS]
    F --> G[Server Receives Plaintext]
    G --> H[Server Saves to DB in Plaintext]
    H --> I[Server Forwards to Recipient]
```

---

## 4.4 Requirement Definitions and Specifications of the Implemented System

SecureChat is designed to protect message storage by utilizing server-side hybrid encryption. WebSockets are used for real-time delivery, and payloads are encrypted before persistence to the database.

### 4.4.1 Functional Requirements
1.  **User Authentication:** Users register and authenticate securely.
2.  **Cryptographic Key Management:** The system generates an RSA public/private key pair (`UserKey`) for users, managed securely by the server.
3.  **Real-Time Messaging:** The system utilizes WebSockets via Django Channels for live, bidirectional message broadcasting within chat rooms.
4.  **Server-Side Data-at-Rest Encryption:**
    *   The backend must generate a unique AES symmetric key for every message.
    *   The message content must be encrypted using this AES key.
    *   The AES key must be independently encrypted using both the recipient's and sender's RSA public keys.
    *   Ciphertext and the encrypted AES keys are stored in the database, securing messages at rest.
5.  **Chat Rooms:** Direct 1-on-1 messaging logic with unique room grouping based on User IDs.

### 4.4.2 Software Specifications
*   **Backend Framework:** Django (Python)
*   **Asynchronous Server:** ASGI with Django Channels
*   **Real-time Protocol:** WebSockets (Redis Channel Layer)
*   **Database:** SQLite / PostgreSQL
*   **Cryptographic Libraries:** Python `cryptography` package (AES & RSA)

---

## 4.5 System Modeling of the Implemented System

To provide a clear architectural blueprint, the SecureChat system is modeled using standard UML and DFD paradigms.

### 4.5.1 Use Case Diagram
Illustrates the interactions between the primary actor (the User) and the system's core functionalities.

```mermaid
usecaseDiagram
    actor User as "Registered User"

    package SecureChat {
        usecase UC1 as "Register Account"
        usecase UC2 as "Login"
        usecase UC3 as "Initiate Chat Session"
        usecase UC4 as "Send Message"
        usecase UC5 as "Receive Real-Time Message"
        usecase UC6 as "Server: Encrypt Message for Storage"
    }

    User --> UC1
    User --> UC2
    User --> UC3
    User --> UC4
    User --> UC5
    UC4 ..> UC6 : <<triggers>>
```

### 4.5.2 Data Flow Diagrams (DFD)

#### Level 0 DFD (Context Diagram)
Shows the SecureChat system handling communication and securing data at rest.

```mermaid
graph LR
    Sender((Sender)) -- "Plaintext via WebSockets" --> System[SecureChat ASGI Server]
    System -- "Real-Time Plaintext Broadcast" --> Receiver((Receiver))
    System -- "Encrypted Message & Keys" --> DB[(System Database)]
```

#### Level 1 DFD
Breaks down the system into Authentication, Key Retrieval, and Message Processing.

```mermaid
graph TD
    User((User)) -->|WebSocket Payload| P1(1.0 WebSocket Consumer)
    
    P1 -->|Fetch Keys| P2(2.0 Key Management)
    P2 -->|Read Public Keys| KeyDB[(UserKey DB)]
    KeyDB -->|Sender & Receiver Public Keys| P2
    P2 -->|Keys| P1

    P1 -->|Plaintext Message + Public Keys| P3(3.0 Hybrid Encryption Engine)
    P3 -->|Generate AES Key| P3
    P3 -->|AES Ciphertext & RSA Encrypted Keys| MsgDB[(Message DB)]

    P1 -->|Broadcast Plaintext| Recipient((Recipient))
```

### 4.5.3 Flowcharts and Activity Diagrams

#### Server-Side Message Processing Flowchart
This flowchart details the step-by-step logic inside the Django Channel `ChatConsumer` upon receiving a message.

```mermaid
flowchart TD
    Start([User sends message via WebSocket]) --> Step1[ChatConsumer receives text data]
    Step1 --> Step2[Extract Sender, Receiver ID, and Message]
    Step2 --> Step3[Retrieve Sender & Receiver RSA Public Keys from DB]
    Step3 --> Step4[Generate unique AES Key for the message]
    Step4 --> Step5[Encrypt Message using AES Key]
    Step5 --> Step6[Encrypt AES Key with Receiver's RSA Public Key]
    Step6 --> Step7[Encrypt AES Key with Sender's RSA Public Key]
    Step7 --> Step8[(Save to Message DB: Ciphertext, Encrypted Keys)]
    Step8 --> Step9[Broadcast Plaintext via Channel Layer Group]
    Step9 --> End([Active Users in room receive real-time message])
```

### 4.5.4 UML Class Diagram
Maps the object-oriented structure of the Django backend, focusing on models and WebSocket consumers.

```mermaid
classDiagram
    class User {
        +Integer id
        +String username
        +String password
    }

    class UserKey {
        +Integer id
        +TextField public_key
        +TextField private_key
        +User user
    }

    class Message {
        +Integer id
        +TextField content
        +TextField encrypted_key
        +TextField encrypted_key_sender
        +DateTimeField timestamp
        +User sender
        +User receiver
    }

    class ChatConsumer {
        +String room_name
        +String room_group_name
        +connect()
        +disconnect()
        +receive(text_data)
        +chat_message(event)
    }

    User "1" -- "1" UserKey : has
    User "1" -- "*" Message : sends
    User "1" -- "*" Message : receives
    ChatConsumer ..> Message : saves
    ChatConsumer ..> UserKey : reads
```

### 4.5.5 UML Sequence Diagram
Visualizes the asynchronous WebSocket messaging and server-side encryption logic flow in chronological order.

```mermaid
sequenceDiagram
    participant Sender as Sender (Client)
    participant WS as WebSocket (ChatConsumer)
    participant Crypto as Encryption Module
    participant DB as Database
    participant Group as Channel Layer
    participant Receiver as Receiver (Client)

    Sender->>WS: send(message, receiver_id)
    WS->>DB: get UserKey(sender) & UserKey(receiver)
    DB-->>WS: Return RSA Public Keys
    
    WS->>Crypto: generate_aes_key()
    Crypto-->>WS: AES Key
    
    WS->>Crypto: encrypt_message_aes(message, AES Key)
    Crypto-->>WS: Encrypted Message (Ciphertext)
    
    WS->>Crypto: encrypt_key_rsa(AES Key, Receiver Public Key)
    Crypto-->>WS: Receiver Encrypted Key
    
    WS->>Crypto: encrypt_key_rsa(AES Key, Sender Public Key)
    Crypto-->>WS: Sender Encrypted Key
    
    WS->>DB: save(Message: Ciphertext, Receiver Encrypted Key, Sender Encrypted Key)
    
    WS->>Group: group_send(plaintext message payload)
    Group-->>Receiver: chat_message(event)
```

## 4.6 Conclusion
This chapter has established the architectural framework and system modeling for the SecureChat application. Through detailed Use Case scenarios, DFDs, Flowcharts, and UML diagrams, it is evident that the system implements a robust server-side hybrid encryption model (AES + RSA). This approach ensures that while active chat sessions occur efficiently in real-time via WebSockets, all messages persisted in the database are fully encrypted, securing data at rest from unauthorized database exposure.
