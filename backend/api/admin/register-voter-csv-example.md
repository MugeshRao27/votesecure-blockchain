# Voter Registration CSV API - Usage Guide

## Overview
This API endpoint automatically creates and maintains CSV files for voter registration. Each election has its own CSV file stored in the `elections/` folder.

## Endpoint
```
POST /backend/api/admin/register-voter-csv.php
```

## Authentication
- Requires admin authentication via JWT token
- Include token in Authorization header: `Bearer <your-jwt-token>`

## Request Format

### Headers
```
Content-Type: application/json
Authorization: Bearer <your-jwt-token>
```

### Request Body (JSON)
```json
{
  "name": "John Doe",
  "email": "john.doe@example.com",
  "dob": "1990-01-15",
  "election_id": 2025
}
```

### Required Fields
- `name` (string): Voter's full name
- `email` (string): Voter's email address (must be valid email format)
- `dob` (string): Date of birth in YYYY-MM-DD format (e.g., "1990-01-15")
- `election_id` (integer): The ID of the election

## Response Format

### Success Response (200)
```json
{
  "success": true,
  "message": "Voter registered successfully and added to CSV file.",
  "data": {
    "voter": {
      "name": "John Doe",
      "email": "john.doe@example.com",
      "date_of_birth": "1990-01-15",
      "registration_timestamp": "2025-01-20 14:30:45"
    },
    "election": {
      "id": 2025,
      "title": "College Student Council Election"
    },
    "csv": {
      "file_created": false,
      "filename": "election_2025_voters.csv",
      "file_path": "/path/to/backend/api/elections/election_2025_voters.csv"
    }
  }
}
```

### Error Responses

#### Missing Fields (400)
```json
{
  "success": false,
  "message": "Missing required fields: email, dob",
  "missing_fields": ["email", "dob"]
}
```

#### Invalid Email (400)
```json
{
  "success": false,
  "message": "Invalid email format. Please provide a valid email address."
}
```

#### Invalid Date Format (400)
```json
{
  "success": false,
  "message": "Invalid date format. Date of birth must be in YYYY-MM-DD format (e.g., 1990-01-15)."
}
```

#### Election Not Found (404)
```json
{
  "success": false,
  "message": "Election with ID 2025 does not exist."
}
```

#### Unauthorized (401)
```json
{
  "success": false,
  "message": "Unauthorized - Admin access required"
}
```

## CSV File Format

### File Location
CSV files are stored in: `backend/api/elections/`

### File Naming
Format: `election_{election_id}_voters.csv`
Example: `election_2025_voters.csv`

### CSV Structure
```csv
Name,Email,Date of Birth,Registration Timestamp
John Doe,john.doe@example.com,1990-01-15,2025-01-20 14:30:45
Jane Smith,jane.smith@example.com,1992-05-20,2025-01-20 15:45:12
```

### Features
- **Automatic Creation**: CSV file is created automatically when the first voter is registered for an election
- **Header Row**: Header row is automatically added when the file is created
- **Append Mode**: New voters are appended as new rows (never overwrites existing data)
- **Registration Timestamp**: Each registration includes a timestamp of when the voter was registered

## Example Usage (JavaScript/Fetch)

```javascript
async function registerVoter(name, email, dob, electionId) {
  const response = await fetch('http://localhost/backend/api/admin/register-voter-csv.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Authorization': `Bearer ${yourJwtToken}`
    },
    body: JSON.stringify({
      name: name,
      email: email,
      dob: dob,
      election_id: electionId
    })
  });
  
  const data = await response.json();
  
  if (data.success) {
    console.log('Voter registered successfully!');
    console.log('CSV file:', data.data.csv.filename);
  } else {
    console.error('Error:', data.message);
  }
  
  return data;
}

// Example call
registerVoter(
  'John Doe',
  'john.doe@example.com',
  '1990-01-15',
  2025
);
```

## Example Usage (cURL)

```bash
curl -X POST http://localhost/backend/api/admin/register-voter-csv.php \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -d '{
    "name": "John Doe",
    "email": "john.doe@example.com",
    "dob": "1990-01-15",
    "election_id": 2025
  }'
```

## Validation Rules

1. **Name**: Cannot be empty
2. **Email**: Must be a valid email format
3. **Date of Birth**: 
   - Must be in YYYY-MM-DD format
   - Cannot be in the future
   - Voter must be at least 18 years old
4. **Election ID**: Must be a positive integer and must exist in the database

## Notes

- The CSV helper functions are reusable and can be imported in other PHP files
- CSV files are created automatically in the `elections/` directory
- The directory is created automatically if it doesn't exist
- All file operations are safe and handle errors gracefully
- Registration timestamps are automatically generated (current server time)

