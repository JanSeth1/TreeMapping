# Building Availability Checker System

This system allows users to submit building requests for specific areas on the map, which admins can then review and approve or reject.

## Setup Instructions

1. First, run the database initialization page:
   - Open `setup_building_system.html` in your browser
   - Click the "Initialize Database" button to create all necessary tables

2. Test the system with the test page:
   - Open `test_building_request.html` in your browser
   - Fill out the form with test data or use the default values
   - Submit the request to verify everything is working

3. Access the admin review interface:
   - Go to `admin_building_requests.php` to view and manage building requests
   - Log in with the admin credentials (for the test system, use User ID: "admin" with any token)

## System Components

### Database Tables

- **building_requests**: Stores all building requests with their details and status
- **enrolled_areas**: Stores the areas that users can request buildings for
- **users**: Stores user information (simplified for the test system)

### Files

- **submit_building_request.php**: Handles the API endpoint for submitting new building requests
- **get_building_requests.php**: Retrieves building requests for a user
- **get_building_request_details.php**: Gets detailed information about a specific request
- **admin_building_requests.php**: Admin interface for reviewing and managing requests
- **setup_building_system.html**: UI for initializing the database
- **test_building_request.html**: Test page for submitting building requests
- **initialize_building_system.php**: Backend script for setting up the database

## Data Flow

1. User selects an area on the map and wants to build a structure
2. User submits a building request with details (structure type, size, coordinates, etc.)
3. Request is saved to the database with status "pending"
4. Admin receives notification about new request (email notification in production)
5. Admin reviews the request in the admin interface
6. Admin approves or rejects the request, optionally adding notes
7. User is notified about the decision
8. User can see the status of their requests in their account

## Development Notes

This system is set up for local development with simplified authentication. In a production environment, you would need to:

1. Implement proper authentication with secure tokens
2. Add proper validation for all requests
3. Enable email notifications
4. Improve error handling and logging
5. Add proper foreign key constraints in the database

## Troubleshooting

If you encounter issues with the building request system:

1. Check that all required tables exist and have the correct structure
2. Verify that the sample data was created correctly
3. Look for PHP errors in the logs
4. Test the API endpoints directly using Postman or similar tools
5. If necessary, run the setup script again to recreate the tables

## API Endpoints

### Submit Building Request
- **URL**: `/submit_building_request.php`
- **Method**: POST
- **Headers**:
  - Content-Type: application/json
  - Authorization: Bearer {token}
  - X-User-ID: {user_id}
- **Body**:
```json
{
  "user_id": "test123",
  "area_id": 1,
  "structure_type": "House",
  "structure_size": 100,
  "project_description": "Two-story house with garden",
  "coordinates": [[lat1, lng1], [lat2, lng2], ...]
}
```

### Get User's Building Requests
- **URL**: `/get_building_requests.php`
- **Method**: GET
- **Headers**:
  - Authorization: Bearer {token}
  - X-User-ID: {user_id}

### Get Building Request Details
- **URL**: `/get_building_request_details.php?id={request_id}`
- **Method**: GET
- **Headers**:
  - Authorization: Bearer {token}
  - X-User-ID: {user_id} 