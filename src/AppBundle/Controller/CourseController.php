<?php

namespace AppBundle\Controller;


use Biz\Course\Service\ReviewService;
use Biz\Task\Service\TaskResultService;
use Biz\Task\Service\TaskService;
use Biz\User\Service\TokenService;
use Symfony\Component\HttpFoundation\Request;
use Topxia\Common\ArrayToolkit;
use Topxia\Common\Paginator;
use Topxia\Service\Common\ServiceKernel;

class CourseController extends CourseBaseController
{
    public function showAction($id)
    {
        list($courseSet, $course) = $this->tryGetCourseSetAndCourse($id);
        $courseItems = $this->getCourseService()->findCourseItems($course['id']);

        return $this->render('course-set/overview.html.twig', array(
            'courseSet'   => $courseSet,
            'course'      => $course,
            'courseItems' => $courseItems
        ));
    }

    public function headerAction(Request $request, $id)
    {

        list($courseSet, $course, $member) = $this->buildCourseLayoutData($request, $id);

        $courses = $this->getCourseService()->findPublishedCoursesByCourseSetId($course['courseSetId']);

        $taskCount = $this->getTaskService()->countTasksByCourseId($id);

        $progress = $taskResultCount = $toLearnTasks = $taskPerDay = $planStudyTaskCount = $planProgressProgress = 0;

        if ($member && $taskCount) {
            $user = $this->getUser();

            //学习记录
            $taskResultCount = $this->getTaskResultService()->countTaskResult(array('courseId' => $id, 'status' => 'finish', 'userId' => $user['id']));

            //学习进度
            $progress = empty($taskCount) ? 0 : round($taskResultCount / $taskCount, 2) * 100;

            //待学习任务
            $toLearnTasks = $this->getTaskService()->findToLearnTasksByCourseId($id);

            //任务式课程每日建议学习任务数
            $taskPerDay = $this->getFinishedTaskPerDay($course, $taskCount);


            //计划应学数量
            $planStudyTaskCount = $this->getPlanStudyTaskCount($course, $member, $taskCount, $taskPerDay);

            //计划进度
            $planProgressProgress = empty($taskCount) ? 0 : round($planStudyTaskCount / $taskCount, 2) * 100;

            $previewTaks = $this->getTaskService()->search(array('courseId' => $id, 'isFree' => '1'), array('seq' => 'ASC'), 0, 1);
        }

        return $this->render('course/header.html.twig', array(
            'courseSet'            => $courseSet,
            'courses'              => $courses,
            'course'               => $course,
            'member'               => $member,
            'progress'             => $progress,
            'taskCount'            => $taskCount,
            'taskResultCount'      => $taskResultCount,
            'toLearnTasks'         => $toLearnTasks,
            'taskPerDay'           => $taskPerDay,
            'planStudyTaskCount'   => $planStudyTaskCount,
            'planProgressProgress' => $planProgressProgress
        ));
    }

    public function detailNavsPartAction(Request $request, $id, $nav = null)
    {
        list($courseSet, $course, $member) = $this->buildCourseLayoutData($request, $id);

        return $this->render('course/part/detail-navs.html.twig', array(
            'courseSet' => $courseSet,
            'course'    => $course,
            'member'    => $member,
            'nav'       => $nav
        ));
    }

    protected function getFinishedTaskPerDay($course, $taskNum)
    {
        //自由式不需要展示每日计划的学习任务数
        if ($course['learnMode'] == 'freeMode') {
            return false;
        }
        if ($course['expiryMode'] == 'days') {
            $finishedTaskPerDay = empty($course['expiryDays']) ? false : $taskNum / $course['expiryDays'];
        } else {
            $diffDay            = ($course['expiryEndDate'] - $course['expiryStartDate']) / (24 * 60 * 60);
            $finishedTaskPerDay = empty($diffDay) ? false : $taskNum / $diffDay;
        }
        return round($finishedTaskPerDay);
    }

    protected function getPlanStudyTaskCount($course, $member, $taskNum, $taskPerDay)
    {
        //自由式不需要展示应学任务数, 未设置学习有效期不需要展示应学任务数
        if ($course['learnMode'] == 'freeMode' || empty($taskPerDay)) {
            return false;
        }
        //当前时间减去课程
        //按天计算有效期， 当前的时间- 加入课程的时间 获得天数* 每天应学任务
        if ($course['expiryMode'] == 'days') {
            $joinDays = (time() - $member['createdTime']) / (24 * 60 * 60);
        } else {
            //当前时间-减去课程有效期开始时间  获得天数 *应学任务数量
            $joinDays = (time() - $course['expiryStartDate']) / (24 * 60 * 60);
        }

        return $taskPerDay * $joinDays >= $taskNum ? $taskNum : round($taskPerDay * $joinDays);

    }

    public function notesAction($id)
    {
        list($courseSet, $course) = $this->tryGetCourseSetAndCourse($id);

        $notes = $this->getCourseNoteService()->findPublicNotesByCourseId($course['id']);

        $users = $this->getUserService()->findUsersByIds(ArrayToolkit::column($notes, 'userId'));
        $users = ArrayToolkit::index($users, 'id');

        $tasks = $this->getTaskService()->findTasksByIds(ArrayToolkit::column($notes, 'taskId'));
        $tasks = ArrayToolkit::index($tasks, 'id');

        $currentUser = $this->getCurrentUser();
        $likes       = $this->getCourseNoteService()->findNoteLikesByUserId($currentUser['id']);
        $likeNoteIds = ArrayToolkit::column($likes, 'noteId');
        return $this->render('course-set/note/notes.html.twig', array(
            'course'      => $course,
            'courseSet'   => $courseSet,
            'notes'       => $notes,
            'users'       => $users,
            'tasks'       => $tasks,
            'likeNoteIds' => $likeNoteIds
        ));
    }

    public function reviewListAction(Request $request, $id)
    {
        list($courseSet, $course, $member) = $this->buildCourseLayoutData($request, $id);

        $conditions = array(
            'courseId' => $course['id'],
            'parentId' => 0
        );

        $paginator = new Paginator(
            $this->get('request'),
            $this->getReviewService()->searchReviewsCount($conditions),
            20
        );

        $reviews = $this->getReviewService()->searchReviews(
            $conditions,
            array('createdTime' => 'DESC'),
            $paginator->getOffsetCount(),
            $paginator->getPerPageCount()
        );

        $user       = $this->getCurrentUser();
        $userReview = $this->getReviewService()->getUserCourseReview($user['id'], $course['id']);

        $users = $this->getUserService()->findUsersByIds(ArrayToolkit::column($reviews, 'userId'));

        return $this->render('course-set/review/list.html.twig', array(
            'courseSet'  => $courseSet,
            'course'     => $course,
            'reviews'    => $reviews,
            'userReview' => $userReview,
            'users'      => $users,
            'member'     => $member
        ));
    }

    public function coursesBlockAction($courses, $view = 'list', $mode = 'default')
    {
        $userIds = array();

        foreach ($courses as $key => $course) {
            //TODO
            // $userIds = array_merge($userIds, $course['teacherIds']);

            $classroomIds = $this->getClassroomService()->findClassroomIdsByCourseId($course['id']);

            $courses[$key]['classroomCount'] = count($classroomIds);
            $courses[$key]['courseSet']      = $this->getCourseSetService()->getCourseSet($course['courseSetId']);
            if (count($classroomIds) > 0) {
                $classroom                  = $this->getClassroomService()->getClassroom($classroomIds[0]);
                $courses[$key]['classroom'] = $classroom;
            }
        }

        $users = $this->getUserService()->findUsersByIds($userIds);

        return $this->render("course/courses-block-{$view}.html.twig", array(
            'courses' => $courses,
            'users'   => $users,
            //'classroomIds' => $classroomIds,
            'mode'    => $mode
        ));
    }

    public function taskListAction(Request $request, $id)
    {
        list($courseSet, $course) = $this->tryGetCourseSetAndCourse($id);
        $courseItems = $this->getCourseService()->findCourseItems($id);

        return $this->render('course-set/task-list.html.twig', array(
            'course'      => $course,
            'courseSet'   => $courseSet,
            'courseItems' => $courseItems
        ));
    }

    public function characteristicPartAction(Request $request, $id)
    {
        $course = $this->getCourseService()->getCourse($id);

        $tasks = $this->getTaskService()->findTasksFetchActivityByCourseId($course['id']);

        $characteristicData = array();
        $activities         = $this->get('extension.default')->getActivities();
        foreach ($tasks as $task) {
            $type = strtolower($task['activity']['mediaType']);

            if (isset($characteristicData[$type])) {
                $characteristicData[$type]['num']++;
            } else {
                $characteristicData[$type] = array(
                    'icon' => $activities[$type]['meta']['icon'],
                    'name' => $activities[$type]['meta']['name'],
                    'num'  => 1
                );
            }
        }

        return $this->render('course/part/characteristic.html.twig', array(
            'course'             => $course,
            'characteristicData' => $characteristicData
        ));
    }

    public function otherCoursePartAction(Request $request, $id)
    {
        list($courseSet, $course) = $this->tryGetCourseSetAndCourse($id);

        $otherCourse = $course; // $this->getCourseService()->getOtherCourses($course['id']);

        return $this->render('course/part/other-course.html.twig', array(
            'otherCourse' => $otherCourse,
            'courseSet'   => $courseSet
        ));
    }

    public function teachersPartAction(Request $request, $id)
    {
        list(, $course) = $this->tryGetCourseSetAndCourse($id);

        $teachers = $this->getUserService()->findUsersByIds($course['teacherIds']);

        return $this->render('course/part/teachers.html.twig', array(
            'teachers' => $teachers
        ));
    }

    public function newestStudentsPartAction(Request $request, $id)
    {
        list(, $course) = $this->tryGetCourseSetAndCourse($id);

        $conditions = array(
            'courseId' => $course['id'],
            'role'     => 'student',
            'locked'   => 0
        );

        $members    = $this->getMemberService()->searchMembers($conditions, array('createdTime' => 'DESC'), 0, 20);
        $studentIds = ArrayToolkit::column($members, 'userId');
        $students   = $this->getUserService()->findUsersByIds($studentIds);

        return $this->render('course/part/newest-students.html.twig', array(
            'students' => $students
        ));
    }

    public function orderInfoAction(Request $request, $sn)
    {
        $order = $this->getOrderService()->getOrderBySn($sn);

        if (empty($order)) {
            throw $this->createNotFoundException($this->getServiceKernel()->trans('订单不存在!'));
        }

        $course = $this->getCourseService()->getCourse($order['targetId']);

        if (empty($course)) {
            throw $this->createNotFoundException($this->getServiceKernel()->trans('课程不存在，或已删除。'));
        }

        return $this->render('TopxiaWebBundle:Course:course-order.html.twig', array('order' => $order, 'course' => $course));
    }

    public function headerTopPartAction(Request $request, $id)
    {
        list($courseSet, $course, $member) = $this->buildCourseLayoutData($request, $id);

        $user           = $this->getCurrentUser();
        $isUserFavorite = $canManage = false;
        if ($user->isLogin()) {
            $isUserFavorite = $this->getCourseSetService()->isUserFavorite($user['id'], $course['courseSetId']);
            $canManage      = $this->getCourseService()->hasCourseManagerRole($course['id'], $courseSet['id']);
        }

        return $this->render('course/part/header-top.html.twig', array(
            'course'         => $course,
            'courseSet'      => $courseSet,
            'isUserFavorite' => $isUserFavorite,
            'canManage'      => $canManage,
            'member'         => $member
        ));
    }

    public function qrcodeAction(Request $request, $id)
    {
        $user  = $this->getCurrentUser();
        $host  = $request->getSchemeAndHttpHost();
        $token = $this->getTokenService()->makeToken('qrcode', array(
            'userId'   => $user['id'],
            'data'     => array(
                'url'    => $this->generateUrl('course_show', array('id' => $id), true),
                'appUrl' => "{$host}/mapi_v2/mobile/main#/course/{$id}"
            ),
            'times'    => 1,
            'duration' => 3600
        ));
        $url   = $this->generateUrl('common_parse_qrcode', array('token' => $token['token']), true);

        $response = array(
            'img' => $this->generateUrl('common_qrcode', array('text' => $url), true)
        );
        return $this->createJsonResponse($response);
    }

    public function exitAction(Request $request, $id)
    {
        list($course, $member) = $this->getCourseService()->tryTakeCourse($id);
        $user = $this->getCurrentUser();
        if (empty($member)) {
            throw $this->createAccessDeniedException($this->getServiceKernel()->trans('您不是课程的学员。'));
        }

        if ($member["joinedType"] == "course" && !empty($member['orderId'])) {
            throw $this->createAccessDeniedException($this->getServiceKernel()->trans('有关联的订单，不能直接退出学习。'));
        }

        $this->getCourseMemberService()->removeStudent($course['id'], $user['id']);

        return $this->createJsonResponse(true);
    }

    // TODO old
    protected function getClassroomService()
    {
        return $this->createService('Classroom:ClassroomService');
    }

    /**
     * @return CourseNoteService
     */
    protected function getCourseNoteService()
    {
        return $this->createService('Note:CourseNoteService');
    }

    /**
     * @return TaskService
     */
    protected function getTaskService()
    {
        return $this->createService('Task:TaskService');
    }

    /**
     * @return TaskResultService
     */
    protected function getTaskResultService()
    {
        return $this->createService('Task:TaskResultService');
    }

    /**
     * @return ReviewService
     */
    protected function getReviewService()
    {
        return $this->createService('Course:ReviewService');
    }

    protected function getOrderService()
    {
        return $this->createService('Order:OrderService');
    }

    protected function getServiceKernel()
    {
        return ServiceKernel::instance();
    }

    /**
     * @return TokenService
     */
    protected function getTokenService()
    {
        return $this->createService('User:TokenService');
    }
}
