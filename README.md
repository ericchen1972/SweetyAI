# SweetyAI: Agent-based Matchmaking System

**SweetyAI** is an AI-powered matchmaking prototype designed to facilitate meaningful connections by employing autonomous AI agents. Instead of humans swiping endlessly, each user has a dedicated **Agent** that learns their personality, preferences, and memories. These agents then negotiate and interact with other agents to find the best potential matches.

> *Note: This repository contains the source code for the prototype presented at the hackathon. For security reasons, API keys and sensitive database credentials have been removed. The system requires a configured Line Bot and MySQL environment to run.*

## 🚀 Key Features

*   **Autonomous Agents**: Every user is represented by an AI Agent that understands their owner's profile and dating criteria.
*   **Agent Negotiation**: Before any human interaction occurs, the two Agents meet in a virtual environment to discuss their owners' compatibility.
*   **Context Awareness**: Agents utilize compressed memories (vector-like summaries) to make informed decisions, ensuring matches go beyond surface-level stats.
*   **Match Reports**: If the agents agree on a high compatibility score (>70%), they generate a detailed "Match Report" for their human owners.

## 🛠️ Architecture Overview

The system is built using PHP backend logic integrated with Line Messaging API and OpenAI.

1.  **User Interaction**: Users interact with SweetyAI via Line Messenger.
2.  **Webhook Handler (`hook.php`)**: Processes incoming messages, updates user state, and triggers agent responses.
3.  **Matchmaking Engine (`ai_match_maker.php`)**:
    *   Runs as a cron job to periodically scan for potential candidates.
    *   Filters based on hard criteria (Age, Location).
    *   Initiates "Agent-to-Agent" conversation simulation.
    *   Evaluates compatibility and sends notifications.

## 📦 Setup & Configuration

1.  **Database**: Import the schema (not included in this public repo) to setting up `ai_member`, `connections`, and `wsystem` tables.
2.  **Configuration**:
    *   Rename `config.example.php` to `config.php`.
    *   Fill in your OpenAI API Key and Line Channel tokens.
3.  **Dependencies**: Run `composer install` to setup the `openai-php/client`.

## 🤖 The "Agent Match" Flow

1.  **Discovery**: System selects a candidate from the pool.
2.  **Introduction**: `Eric Agent` introduces Eric to `Judy Agent`.
3.  **Response**: `Judy Agent` evaluates the introduction and replies based on Judy's preferences.
4.  **Scoring**: Both agents secretly score the interaction (0-100).
5.  **Result**: If `Score > 70` from both sides, a match is declared!

---
*Created for Hackathon Demo purposes.*
