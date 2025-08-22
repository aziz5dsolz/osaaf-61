<?php


namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Yajra\DataTables\Facades\DataTables;

class UserController extends Controller
{

    public function index()
    {
        return view('admin.users');
    }

    public function notification(Request $request)
    {
        $data['notifications'] = Notification::where('notify_to', Auth()->user()->id)->with(['user'])->orderBy('id', 'desc')->get();
        return view('user.notification')->with($data);
    }


    public function getUsers(Request $request)
    {
        // Check if this is a sidebar request for field of study filter
        $sidebarFilter = $request->get('sidebar_filter');
        $searchTerm = $request->get('search_term', '');

        // Handle sidebar filtering first (keeping original logic)
        if ($sidebarFilter) {
            $records = User::query()->whereIn('role', ['1', '2'])
                ->select('id', 'first_name', 'last_name', 'image', 'email', 'status', 'created_at', 'last_activity_at');

            if ($sidebarFilter == 'with_field_of_study') {
                $records = $records->with('additionalInfo')->whereHas('additionalInfo', function ($query) use ($searchTerm) {
                    $query->whereNotNull('field_of_study')
                        ->where('field_of_study', '!=', '');

                    if (!empty($searchTerm)) {
                        $query->where(function ($subQuery) use ($searchTerm) {
                            $subQuery->where('field_of_study', 'LIKE', "%$searchTerm%");
                        });
                    }
                });

                if (!empty($searchTerm)) {
                    $records = $records->where(function ($query) use ($searchTerm) {
                        $query->where('first_name', 'LIKE', "%$searchTerm%")
                            ->orWhere('last_name', 'LIKE', "%$searchTerm%")
                            ->orWhere('email', 'LIKE', "%$searchTerm%");
                    });
                }
            } elseif ($sidebarFilter == 'all') {
                if (!empty($searchTerm)) {
                    $records = $records->where(function ($query) use ($searchTerm) {
                        $query->where('first_name', 'LIKE', "%$searchTerm%")
                            ->orWhere('last_name', 'LIKE', "%$searchTerm%")
                            ->orWhere('email', 'LIKE', "%$searchTerm%");
                    });
                }
            }

            $records = $records->orderByRaw('status DESC, created_at DESC')->limit(50)->get();

            $usersWithFieldOfStudy = User::whereIn('role', ['1', '2'])
                ->whereHas('additionalInfo', function ($query) {
                    $query->whereNotNull('field_of_study')
                        ->where('field_of_study', '!=', '');
                })->count();

            // Generate HTML for sidebar with real-time online status
            $html = '';
            if ($records->count() > 0) {
                foreach ($records as $user) {
                    $imageUrl = $user->image ? asset($user->image) : 'https://ui-avatars.com/api/?name=' . urlencode($user->first_name . ' ' . $user->last_name) . '&background=random';

                    // Use the isOnline() method instead of status field
                    $isOnline = $user->isOnline();
                    $isEnabled = $user->status == '1'; // Account enabled/disabled status

                    // Determine final status (must be enabled AND online to show as active)
                    $showAsActive = $isEnabled && $isOnline;

                    $statusIndicator = $showAsActive ? '<div class="active_circle"></div>' : '<div class="inactive_circle"></div>';
                    $statusTitle = $showAsActive ? 'Online' : ($isEnabled ? 'Offline' : 'Account Disabled');

                    $fieldOfStudyInfo = '';
                    if ($sidebarFilter == 'with_field_of_study' && $user->additionalInfo && $user->additionalInfo->field_of_study) {
                        $fieldOfStudyInfo = '<small class="text-muted d-block">' . $user->additionalInfo->field_of_study . '</small>';
                    }

                    $html .= '<div class="d-flex align-items-center justify-content-between mb-3 user-item">
                <div class="d-flex align-items-center gap-2">
                    <img src="' . $imageUrl . '"
                        width="30" height="30" class="object-fit-cover" style="border-radius: 50px"
                        alt="' . $user->first_name . ' ' . $user->last_name . '">
                    <div>
                        <h6 class="mb-0">' . $user->first_name . ' ' . $user->last_name . '</h6>
                        ' . $fieldOfStudyInfo . '
                    </div>
                </div>
                <div class="user-status-indicator" title="' . $statusTitle . '">
                    ' . $statusIndicator . '
                </div>
            </div>';
                }
            } else {
                $noUsersText = $sidebarFilter == 'with_field_of_study'
                    ? (!empty($searchTerm) ? 'No users found with that field of study' : 'No users with education field found')
                    : (!empty($searchTerm) ? 'No users found matching your search' : 'No users found');
                $html = '<div class="text-center py-3">
            <p class="text-muted">' . $noUsersText . '</p>
        </div>';
            }

            return response()->json([
                'status' => 200,
                'html' => $html,
                'education_count' => $usersWithFieldOfStudy
            ]);
        }

        // MAIN TABLE DATA LOGIC with Yajra DataTables
        $query = User::query();
        $filters = json_decode($request->filters, true) ?? [];

        // Apply filters using the existing recordFilter method
        $query = $this->recordFilter($query, $filters);

        return DataTables::of($query)
            ->addColumn('name', function ($user) {
                return $user->first_name . ' ' . $user->last_name;
            })
            ->addColumn('role_display', function ($user) {
                return $user->role == '1' ? 'Sub Admin' : 'User';
            })
            // ->addColumn('status_display', function ($user) {
            //     // Check if account is enabled and user is online
            //     $isEnabled = $user->status == 1;
            //     $isOnline = $user->isOnline();

            //     if (!$isEnabled) {
            //         return '<span class="badge bg-danger p-2 rounded-5 fw-light">Account Disabled</span>';
            //     }

            //     return $isOnline
            //         ? '<span class="badge bg-success p-2 rounded-5 fw-light">Online</span>'
            //         : '<span class="badge bg-secondary p-2 rounded-5 fw-light">Offline</span>';
            // })
            ->addColumn('status_display', function ($user) {
                $isEnabled = $user->status == 1;

                if ($isEnabled) {
                    return '<span class="badge bg-success p-2 rounded-5 fw-light">Enabled</span>';
                } else {
                    return '<span class="badge bg-danger p-2 rounded-5 fw-light">Disabled</span>';
                }
            })

            ->addColumn('created_at_formatted', function ($user) {
                return $user->created_at ? $user->created_at->format('F d, Y') : 'N/A';
            })
            ->addColumn('actions', function ($user) {
                return '<div class="dropdown">
                <button class="btn btn-secondary btn-sm dropdown-toggle"
                    type="button" data-bs-toggle="dropdown"
                    aria-expanded="false">
                    Actions
                </button>
                <ul class="dropdown-menu">
                    <li>
                        <a id="' . $user->id . '" class="dropdown-item user-edit">Edit</a>
                        <a class="dropdown-item user-status" id="' . $user->id . '" data-status="' . $user->status . '">' . ($user->status == 1 ? 'Disable Account' : 'Enable Account') . '</a>
                    </li>
                </ul>
            </div>';
            })
            ->with([
                'total_users' => User::where('role', '2')->count(),
                'active_users' => User::where('role', '2')->where('status', '1')->count(),
                'total_sub_admin' => User::where('role', '1')->count(),
                'active_sub_admin' => User::where('role', '1')->where('status', '1')->count(),
            ])
            ->rawColumns(['status_display', 'actions'])
            ->make(true);
    }

    public function recordFilter($records, $filters)
    {
        $hasFilters = false; // Track if any filters are applied

        // Apply sorting filters
        if (!empty($filters['sort']['column']) && !empty($filters['sort']['order'])) {
            $records->orderBy($filters['sort']['column'], $filters['sort']['order']);
            $hasFilters = true;
        }

        // Apply role filters
        if (!empty($filters['role'])) {
            $records->whereIn('role', $filters['role']);
            $hasFilters = true;
        } else {
            $records->whereIn('role', ['1', '2']);
        }

        // Apply status filters
        if (isset($filters['status']) && $filters['status'] !== '') {
            $records->whereIn('status', $filters['status']);
            $hasFilters = true;
        }

        // Apply date range filters
        if (!empty($filters['date_range']['start']) && !empty($filters['date_range']['end'])) {
            $startDate = $filters['date_range']['start'];
            $endDate = $filters['date_range']['end'] . ' 23:59:59';
            $records->whereBetween('created_at', [$startDate, $endDate]);
            $hasFilters = true;
        }

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $records->where(function ($query) use ($search) {
                $query->where('users.first_name', 'LIKE', "%$search%")
                    ->orWhere('users.last_name', 'LIKE', "%$search%")
                    ->orWhere('users.email', 'LIKE', "%$search%");
            });
            $hasFilters = true;
        }

        return $records;
    }

    public function changeUserStatus(Request $request)
    {
        $id  = $request->id;
        $status  = !$request->status;
        if ($status) {
            $type = '1';
        } else {
            $type = '0';
        }
        $data = User::where('id', $id)->update(['status' => $type]);

        return response()->json(['status' => 200, 'message' => 'Status Change Successfully']);
    }

    public function saveUser(Request $request)
    {
        $validatedData = $request->validate([
            'first_name' => 'required|max:20',
            'last_name' => 'required|max:20',
            'email' => 'required|email|unique:users',
            'ethereum_address' => 'required|max:100',
            'role' => 'required',
        ]);

        if ($request->user_id == '') {
            $record = new User;
        } else {
            $record = User::find($request->user_id);
        }

        $password = '12345678';
        $hashPassword = Hash::make($password);
        $first_name = $request->first_name;
        $last_name = $request->last_name;
        $email = $request->email;
        $role = $request->role;
        $record->first_name = $first_name;
        $record->last_name = $last_name;
        $record->email = $email;
        $record->role = $role;
        $record->ethereum_address = $request->ethereum_address;
        $record->password = $hashPassword;

        $record->save();

        return response()->json(['status' => 200, 'message' => 'User Created Successfully']);
    }

    public function editUser(Request $request)
    {
        $id = $request->id;
        $records = User::find($id);
        return response()->json(['status' => 200, 'records' => $records]);
    }
}
