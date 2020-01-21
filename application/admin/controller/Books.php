<?php

namespace app\admin\controller;

use app\model\Area;
use app\model\Book;
use app\model\Chapter;
use app\model\Photo;
use think\Db;
use think\facade\App;
use think\Request;
use Overtrue\Pinyin\Pinyin;
use function GuzzleHttp\Psr7\str;

class Books extends BaseAdmin
{
    protected $authorService;
    protected $bookService;

    protected function initialize()
    {
        parent::initialize(); // TODO: Change the autogenerated stub
        $this->authorService = new \app\service\AuthorService();
        $this->bookService = new \app\service\BookService();
    }

    public function index()
    {
        $data = $this->bookService->getPagedBooksAdmin(1);
        $books = $data['books'];
        foreach ($books as &$book) {
            $book['chapter_count'] = count($book->chapters);
        }
        $count = $data['count'];
        $this->assign([
            'books' => $books,
            'count' => $count
        ]);
        return view();
    }

    public function search()
    {
        $name = input('book_name');
        $status = input('status');
        $where = [
            ['book_name', 'like', '%' . $name . '%']
        ];
        $data = $this->bookService->getPagedBooksAdmin($status,$where);
        $books = $data['books'];
        foreach ($books as &$book) {
            $book['chapter_count'] = count($book->chapters);
        }
        $count = $data['count'];
        $this->assign([
            'books' => $books,
            'count' => $count
        ]);
        return view('index');
    }

    public function create()
    {
        $areas = Area::all();
        $this->assign('areas', $areas);
        return view();
    }

    public function save(Request $request)
    {
        $book = new Book();
        $data = $request->param();
        $validate = new \app\admin\validate\Book();

        if ($validate->check($data)) {
            if ($this->bookService->getByName($data['book_name'])) {
                $this->error('漫画名已经存在');
            }

            //作者处理
            $author = $this->authorService->getByName($data['author']);
            if (is_null($author)) {//如果作者不存在
                $author = new \app\model\Author();
                $author->author_name = $data['author'];
                $author->save();
            }

            $book->author_id = $author->id;
            $book->author_name = $author->author_name;
            $book->last_time = time();
            $str = $this->convert($data['book_name']); //生成标识

            if (Book::where('unique_id','=',$str)->select()->count() > 0) { //如果已经存在相同标识
                $book->unique_id = md5(time() . mt_rand(1,1000000));
                sleep(0.1);
            } else {
                $book->unique_id = $str;
            }

            $result = $book->save($data);
            if ($result) {
                $dir = App::getRootPath() . '/public/static/upload/book/' . $book->id;
                if (!file_exists($dir)) {
                    mkdir($dir, 0777, true);
                }
                if (count($request->file()) > 0) {
                    $cover = $request->file('cover');
                    $cover->validate(['size' => 2048000, 'ext' => 'jpg,png,gif'])
                        ->move($dir, 'cover.jpg');
                }

                $this->success('添加成功', 'index', '', 1);
            } else {
                $this->error('添加失败');
            }
        } else {
            $this->error($validate->getError());
        }
    }

    public function edit()
    {
        $areas = Area::all();
        $id = input('id');
        $book = Book::with('author')->find($id);
        $this->assign([
            'book' => $book,
            'areas' => $areas
        ]);
        return view();
    }

    public function update(Request $request)
    {
        $data = $request->param();
        $validate = new \app\admin\validate\Book();
        if ($validate->check($data)) {
            //作者处理
            $author = $this->authorService->getByName($data['author']);
            if (is_null($author)) {//如果是新作者
                $author = new \app\model\Author();
                $author->author_name = $data['author'];
                $author->save();
            } else { //如果作者已经存在
                $data['author_id'] = $author->id;
                $data['author_name'] = $author->author_name;
                $data['last_time'] = time();
            }
            $result = Book::update($data);
            if ($result) {
                $dir = App::getRootPath() . '/public/static/upload/book/' . $data['id'];
                if (!file_exists($dir)) {
                    mkdir($dir, 0777, true);
                }
                if (count($request->file()) > 0) {
                    $cover = $request->file('cover');
                    $cover->validate(['size' => 2048000, 'ext' => 'jpg,png,gif'])
                        ->move($dir, 'cover.jpg');
                    //清理浏览器缓存
                    header("Last-Modified: " . gmdate("D, d M Y H:i:s") . "GMT");
                    header("Cache-Control: no-cache, must-revalidate");

                }
                $this->success('编辑成功');
            } else {
                $this->error('编辑失败');
            }
        } else {
            $this->error($validate->getError());
        }
    }

    public function disable()
    {
        $id = input('id');
        if (empty($id) || is_null($id)) {
            return ['status' => 0];
        }
        $book = Book::get($id);
        $result = $book->delete();
        if ($result) {
            return ['status' => 1];
        } else {
            return ['status' => 0];
        }
    }

    public function enable()
    {
        $id = input('id');
        if (empty($id) || is_null($id)) {
            return ['status' => 0];
        }
        $book = Book::onlyTrashed()->find($id);
        $result = $book->restore();
        if ($result) {
            return ['status' => 1];
        } else {
            return ['status' => 0];
        }
    }

    public function disabled()
    {
        $data = $this->bookService->getPagedBooksAdmin(0);
        $books = $data['books'];
        foreach ($books as &$book) {
            $book['chapter_count'] = count($book->chapters);
        }
        $count = $data['count'];
        $this->assign([
            'books' => $books,
            'count' => $count
        ]);
        return view();
    }

    public function delete()
    {
        $id = input('id');
        $book = Book::withTrashed()->find($id);
        $chapters = Chapter::where('book_id', '=', $id)->select(); //按漫画id查找所有章节
        if (count($chapters) > 0) {
            return ['err' => 1, 'msg' => '该漫画下含有章节，请先删除所有章节'];
        }
        $book->delete(true);
        return ['err' => 0, 'msg' => '删除成功'];
    }

    public function deleteAll()
    {
        $ids = input('ids');
        foreach ($ids as $id) {
            $chapters = Chapter::where('book_id', '=', $id)->select(); //按漫画id查找所有章节
            foreach ($chapters as $chapter) {
                $pics = Photo::where('chapter_id', '=', $chapter->id)->select(); //按章节id查找所有图片
                foreach ($pics as $pic) {
                    $pic->delete(); //删除图片
                }
                $chapter->delete(); //删除章节
            }
        }
        Book::destroy($ids,true);
    }

    public function payment(Request $request)
    {
        if ($this->request->isPost()) {
            $validate = new \app\admin\validate\Book();
            $data = $request->param();
            if ($validate->scene('payment')->check($data)) {
                $start_pay = $data['start_pay'];
                $money = $data['money'];
                $area_id = $data['area_id'];
                $start_id = $data['start_id'];
                $sql = 'UPDATE '.$this->prefix.'book SET start_pay=' . $start_pay . ',money=' . $money . ' WHERE 1=1';
                if ($area_id != -1) {
                    $sql = $sql . ' AND area_id=' . $area_id;
                }
                if ($start_id > -1) {
                    $sql = $sql . ' AND id>=' . $start_id;
                }
                Db::query($sql);
                $this->success('批量设置成功');
            } else {
                $this->error($validate->getError());
            }

        }
        $areas = Area::all();
        $this->assign('areas', $areas);
        return view();
    }

    protected function convert($str){
        $pinyin = new Pinyin();
        $name_format = config('seo.name_format');
        switch ($name_format) {
            case 'pure':
                $arr = $pinyin->convert($str);
                $str = implode($arr,'');
                halt($str);
                break;
            case 'abbr':
                $str = $pinyin->abbr($str);break;
            default:
                $str = $pinyin->convert($str);break;
        }
        return $str;
    }
}
