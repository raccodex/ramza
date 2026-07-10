const funcs = require('../functions/functions')
const compiledTemplates = require('../compiledTemplates/compiledTemplates')
const socketEvents = require('../events/events')
const { Sequelize, Op, DataTypes } = require("sequelize");
const striptags = require('striptags');
const moment = require("moment")

const JoinController = async (ctx, data, io,socket,callback) => {
  if (data.user_id === '') {
      console.log("killing connection user_id not received")
      socket.disconnect(true)
      return
  }
  let user_id = await ctx.wo_appssessions.findOne({
      attributes: [
          "user_id",
      ],
      where: {
          session_id: data.user_id
      }
  })
  if (user_id == null) {
      console.log("User is not found! Please check if you are logged in, or you are using the same database as written in config.php. You can edit MySQL settings in nodejs/config.json")
      socket.disconnect(true)
      return;
  }
  user_id = user_id.user_id;

  let user_status = await ctx.wo_users.findOne({
      attributes: [
          "status"
      ],
      where: {
          user_id: user_id
      }
  })
  user_status = user_status.status;

  ctx.socketIdUserHash[socket.id] = data.user_id;
  ctx.userIdSocket[user_id] ? ctx.userIdSocket[user_id].push(socket) : ctx.userIdSocket[user_id] = [socket]
  ctx.userHashUserId[data.user_id] = user_id;
  ctx.userIdCount[user_id] = ctx.userIdCount[user_id] ? ctx.userIdCount[user_id] + 1 : 1;

  if (data.recipient_ids && data.recipient_ids.length) {
      for (let recipient_id of data.recipient_ids) {
          ctx.userIdChatOpen[ctx.userHashUserId[data.user_id]] && ctx.userIdChatOpen[ctx.userHashUserId[data.user_id]].length ? ctx.userIdChatOpen[ctx.userHashUserId[data.user_id]].push(recipient_id) : ctx.userIdChatOpen[ctx.userHashUserId[data.user_id]] = [recipient_id]
      }
  }

  if (data.recipient_group_ids && data.recipient_group_ids.length) {
      for (let recipient_id of data.recipient_group_ids) {
          ctx.userIdGroupChatOpen[ctx.userHashUserId[data.user_id]] && ctx.userIdGroupChatOpen[ctx.userHashUserId[data.user_id]].length ? ctx.userIdGroupChatOpen[ctx.userHashUserId[data.user_id]].push(recipient_id) : ctx.userIdGroupChatOpen[ctx.userHashUserId[data.user_id]] = [recipient_id]
      }
  }

  await socketEvents.emitUserStatus(ctx, socket, ctx.userHashUserId[data.user_id])
  if (user_status == 0) {
      let followers = await ctx.wo_followers.findAll({
          attributes: ["following_id"],
          where: {
              follower_id: user_id,
              following_id: {
                  [Op.not]: user_id
              }
          },
          raw: true
      })

      for (let follow of followers) {
          await io.to(follow.following_id).emit("on_user_loggedin", { user_id: user_id })
      }
  }

  socket.join(user_id);
  //subscribe to all groups
  let groupIds = await funcs.getAllGroupsForUser(ctx, user_id)
  for (let groupId of groupIds) {
      socket.join("group" + groupId.group_id)
  }
  callback()
};

module.exports = { JoinController };