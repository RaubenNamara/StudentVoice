<?php

declare(strict_types=1);

use StudentVoice\Controllers\AdminController;
use StudentVoice\Controllers\AuthController;
use StudentVoice\Controllers\PollController;
use StudentVoice\Controllers\ResultController;
use StudentVoice\Controllers\StudentController;
use StudentVoice\Controllers\TeacherController;
use StudentVoice\Controllers\TeacherResultController;
use StudentVoice\Controllers\VoteController;
use StudentVoice\Http\Router;

$router = new Router();

$public = ['auth' => false];
$student = ['auth' => 'student'];
$super = ['super' => true];

// Public
$router->get('/api/me', [AuthController::class, 'me'], $public);
$router->post('/api/admin/login', [AuthController::class, 'adminLogin'], $public);
$router->post('/api/vote/login', [AuthController::class, 'studentLogin'], $public);

// Students voting (signed in with a voting number)
$router->post('/api/vote/logout', [AuthController::class, 'studentLogout'], $student);
$router->get('/api/vote/polls', [VoteController::class, 'polls'], $student);
$router->get('/api/vote/polls/{id}', [VoteController::class, 'poll'], $student);
$router->post('/api/vote/polls/{id}', [VoteController::class, 'submit'], $student);
$router->post('/api/vote/polls/{id}/skip', [VoteController::class, 'skip'], $student);

// Admins
$router->post('/api/admin/logout', [AuthController::class, 'adminLogout'], ['before_password' => true]);
$router->post('/api/admin/password', [AuthController::class, 'changePassword'], ['before_password' => true]);
$router->get('/api/admin/overview', [ResultController::class, 'overview']);

$router->get('/api/admin/students', [StudentController::class, 'index']);
$router->post('/api/admin/students', [StudentController::class, 'store']);
$router->get('/api/admin/students/classes', [StudentController::class, 'classes']);
$router->get('/api/admin/students/slips', [StudentController::class, 'slips']);
$router->get('/api/admin/students/sync', [StudentController::class, 'syncStatus']);
$router->post('/api/admin/students/sync', [StudentController::class, 'sync']);
$router->patch('/api/admin/students/{id}', [StudentController::class, 'update']);
$router->post('/api/admin/students/{id}/new-code', [StudentController::class, 'newCode']);
$router->post('/api/admin/students/{id}/status', [StudentController::class, 'setStatus']);

$router->get('/api/admin/polls', [PollController::class, 'index']);
$router->post('/api/admin/polls', [PollController::class, 'store']);
$router->get('/api/admin/polls/{id}', [PollController::class, 'show']);
$router->patch('/api/admin/polls/{id}', [PollController::class, 'update']);
$router->delete('/api/admin/polls/{id}', [PollController::class, 'destroy']);
$router->post('/api/admin/polls/{id}/questions', [PollController::class, 'saveQuestions']);
$router->post('/api/admin/polls/{id}/status', [PollController::class, 'setStatus']);
$router->post('/api/admin/polls/{id}/duplicate', [PollController::class, 'duplicate']);
$router->get('/api/admin/polls/{id}/results', [ResultController::class, 'show']);
$router->get('/api/admin/polls/{id}/teachers', [TeacherResultController::class, 'ranking']);
$router->get('/api/admin/polls/{id}/teachers/{teacher}/{subject}', [TeacherResultController::class, 'report']);

// Teachers (from Tusome) and the Teaching list
$router->get('/api/admin/teachers', [TeacherController::class, 'index']);
$router->post('/api/admin/teachers/sync', [TeacherController::class, 'sync']);
$router->get('/api/admin/teaching', [TeacherController::class, 'grid']);
$router->post('/api/admin/teaching', [TeacherController::class, 'setCell']);
$router->post('/api/admin/teaching/fill-class', [TeacherController::class, 'fillClass']);

// Super admins: the admin accounts
$router->get('/api/admin/admins', [AdminController::class, 'index'], $super);
$router->post('/api/admin/admins', [AdminController::class, 'store'], $super);
$router->patch('/api/admin/admins/{id}', [AdminController::class, 'update'], $super);
$router->post('/api/admin/admins/{id}/reset-password', [AdminController::class, 'resetPassword'], $super);

return $router;
