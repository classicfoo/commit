## Overview
A Post represents a message made by a User within the context
of a Commitment.

## Properties
- [[Check_in]]
- [[Comment]]

## Rules
- A Post belongs to exactly one User
- A Post belongs to exactly one Commitment
- Any User may create a Post on any Commitment

## Post Types

check_in
- Represents an accountability update by the Commitment owner
- May be evaluated against Requirements

comment
- Represents conversational or supportive content
- Is never evaluated against Requirements

### Notes
- Posts are the primary activity within a Commitment
- Only check-in Posts influence Requirement evaluation
